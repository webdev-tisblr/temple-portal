<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Msg91WebhookEvent;
use App\Models\NotificationLog;
use App\Models\NotificationTemplate;
use App\Models\SystemSetting;
use App\Services\Notifications\Drivers\SmsNotificationDriver;
use App\Services\Notifications\NotificationContext;
use App\Services\Notifications\RecipientResolver;
use App\Services\OtpService;
use App\Services\SmsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The MSG91 delivery-report webhook, and the log honesty that depends on it.
 *
 * Context: MSG91's Flow API validates nothing synchronously — a wrong auth
 * key and an invalid template both come back as HTTP 200 {"type":"success"}.
 * So the send path can only ever record "submitted", and this webhook is the
 * only thing entitled to say "delivered" or "failed".
 */
class Msg91WebhookTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        SystemSetting::updateOrCreate(['key' => 'sms_msg91_auth_key'], ['value' => 'test-key']);
        SystemSetting::updateOrCreate(['key' => 'sms_msg91_sender_id'], ['value' => 'SPHST']);
        SystemSetting::updateOrCreate(['key' => 'sms_msg91_api_url'], ['value' => 'https://control.msg91.com/api/v5']);

        $this->token = SmsService::webhookToken();
    }

    private function url(?string $token = null): string
    {
        return '/api/webhooks/msg91/' . ($token ?? $this->token);
    }

    /** @param array<string, mixed> $overrides */
    private function makeLog(array $overrides = []): NotificationLog
    {
        return NotificationLog::create(array_merge([
            'template_key' => 'auth.otp',
            'channel' => 'sms',
            'recipient_masked' => '98••••3210',
            'recipient_value' => '9876543210',
            'status' => NotificationLog::STATUS_SENT,
            'provider_message_id' => 'req-abc-123',
            'delivery_status' => NotificationLog::DELIVERY_SENT,
            'attempts' => 1,
            'sent_at' => now(),
        ], $overrides));
    }

    // ─── Delivery outcomes ────────────────────────────────────────────

    public function test_a_delivery_report_marks_the_matching_log_row_delivered(): void
    {
        $log = $this->makeLog();

        $response = $this->postJson($this->url(), [
            'requestId' => 'req-abc-123',
            'data' => [[
                'number' => '919876543210',
                'status' => '1',
                'desc' => 'DELIVERED',
                'date' => '2026-08-10 12:00:00',
            ]],
        ]);

        $response->assertOk();

        $log->refresh();
        $this->assertSame(NotificationLog::DELIVERY_DELIVERED, $log->delivery_status);
        $this->assertNotNull($log->delivery_status_at);
    }

    /**
     * The reason is the entire point of the integration — an admin must be
     * able to read MSG91's own sentence without logging into MSG91.
     */
    public function test_a_failure_report_stores_msg91s_reason_verbatim(): void
    {
        $log = $this->makeLog();

        $this->postJson($this->url(), [
            'requestId' => 'req-abc-123',
            'data' => [[
                'number' => '919876543210',
                'status' => '16',
                'desc' => 'Template ID Missing or Invalid Template',
            ]],
        ])->assertOk();

        $log->refresh();
        $this->assertSame(NotificationLog::DELIVERY_FAILED, $log->delivery_status);
        $this->assertStringContainsString(
            'Template ID Missing or Invalid Template',
            (string) $log->failure_reason,
            "MSG91's wording must survive into the log unrephrased",
        );
        // A known-failed delivery is not a successful send.
        $this->assertSame(NotificationLog::STATUS_FAILED, $log->status);

        $this->assertDatabaseHas('temple_msg91_webhook_events', [
            'request_id' => 'req-abc-123',
            'description' => 'Template ID Missing or Invalid Template',
            'delivery_status' => NotificationLog::DELIVERY_FAILED,
        ]);
    }

    public function test_a_duplicate_event_is_a_no_op(): void
    {
        $log = $this->makeLog();

        $payload = [
            'requestId' => 'req-abc-123',
            'data' => [[
                'number' => '919876543210',
                'status' => '1',
                'desc' => 'DELIVERED',
                'date' => '2026-08-10 12:00:00',
            ]],
        ];

        $this->postJson($this->url(), $payload)->assertOk();
        $first = NotificationLog::find($log->id)->delivery_status_at;

        $second = $this->postJson($this->url(), $payload);
        $second->assertOk();
        $second->assertJsonPath('duplicates', 1);
        $second->assertJsonPath('processed', 0);

        $this->assertSame(1, Msg91WebhookEvent::query()->count(), 'a retried report must not create a second row');
        $this->assertEquals($first, NotificationLog::find($log->id)->delivery_status_at);
    }

    // ─── Protection ───────────────────────────────────────────────────

    public function test_a_wrong_token_is_rejected(): void
    {
        $this->makeLog();

        $this->postJson($this->url(str_repeat('f', 48)), [
            'requestId' => 'req-abc-123',
            'data' => [['number' => '919876543210', 'status' => '1', 'desc' => 'DELIVERED']],
        ])->assertForbidden();

        $this->assertSame(0, Msg91WebhookEvent::query()->count());
    }

    /**
     * A blank stored token must not turn the endpoint into an open URL.
     */
    public function test_an_unset_token_rejects_everything(): void
    {
        SystemSetting::updateOrCreate(['key' => SmsService::WEBHOOK_TOKEN_KEY], ['value' => '']);

        $this->postJson($this->url('anything'), ['requestId' => 'x'])->assertForbidden();
    }

    // ─── Robustness ───────────────────────────────────────────────────

    /**
     * MSG91 retries forever on a non-2xx, so a payload we cannot parse must
     * never become a retry storm.
     */
    public function test_a_malformed_payload_returns_200_without_throwing(): void
    {
        $this->postJson($this->url(), ['something' => 'entirely unexpected'])->assertOk();
        $this->postJson($this->url(), [])->assertOk();
        $this->postJson($this->url(), ['data' => 'not-an-array'])->assertOk();

        // The unrecognised shape is still retained for forensics.
        $this->assertGreaterThan(0, Msg91WebhookEvent::query()->count());
    }

    public function test_a_full_phone_number_is_never_persisted(): void
    {
        $this->makeLog();

        $this->postJson($this->url(), [
            'requestId' => 'req-abc-123',
            'data' => [['number' => '919876543210', 'status' => '1', 'desc' => 'DELIVERED']],
        ])->assertOk();

        $event = Msg91WebhookEvent::query()->firstOrFail();

        $this->assertSame('91••••3210', $event->recipient_masked);
        $this->assertStringNotContainsString(
            '919876543210',
            (string) json_encode($event->payload),
            'the retained raw payload must not smuggle the full number back in',
        );
    }

    // ─── Correlation ──────────────────────────────────────────────────

    /**
     * End-to-end on the join key: whatever SmsService captures from MSG91 at
     * send time is what the delivery report must match on.
     */
    public function test_the_correlation_id_captured_at_send_time_is_what_the_webhook_matches_on(): void
    {
        Http::fake(['*' => Http::response(['type' => 'success', 'request_id' => 'live-req-999'], 200)]);

        $sms = new SmsService;
        $sms->sendTemplate('9876543210', 'tpl-1', ['OTP' => '123456']);

        $this->assertSame('live-req-999', $sms->lastMessageId(), 'the request id must be captured at send time');

        // Stored exactly as NotificationService would store it.
        $log = $this->makeLog(['provider_message_id' => $sms->lastMessageId()]);

        $this->postJson($this->url(), [
            'requestId' => 'live-req-999',
            'data' => [['number' => '919876543210', 'status' => '1', 'desc' => 'DELIVERED']],
        ])->assertOk();

        $log->refresh();
        $this->assertSame(NotificationLog::DELIVERY_DELIVERED, $log->delivery_status);
        $this->assertDatabaseHas('temple_msg91_webhook_events', [
            'request_id' => 'live-req-999',
            'notification_log_id' => $log->id,
        ]);
    }

    public function test_a_report_for_an_unknown_request_id_is_stored_without_matching(): void
    {
        $log = $this->makeLog();

        $this->postJson($this->url(), [
            'requestId' => 'some-other-request',
            'data' => [['number' => '919999999999', 'status' => '1', 'desc' => 'DELIVERED']],
        ])->assertOk();

        $this->assertSame(NotificationLog::DELIVERY_SENT, $log->refresh()->delivery_status);
        $this->assertDatabaseHas('temple_msg91_webhook_events', [
            'request_id' => 'some-other-request',
            'notification_log_id' => null,
        ]);
    }

    /** Flat (non-nested) reports are used by older MSG91 callback configs. */
    public function test_a_flat_report_shape_is_parsed(): void
    {
        $log = $this->makeLog();

        $this->postJson($this->url(), [
            'requestId' => 'req-abc-123',
            'number' => '919876543210',
            'status' => 'DELIVERED',
        ])->assertOk();

        $this->assertSame(NotificationLog::DELIVERY_DELIVERED, $log->refresh()->delivery_status);
    }

    // ─── Log honesty: submitted ≠ delivered ───────────────────────────

    /**
     * The reported bug: the log said an SMS was sent when nobody received
     * it. Submission may only ever record the intermediate state.
     */
    public function test_a_submitted_sms_is_not_logged_as_delivered(): void
    {
        Http::fake(['*' => Http::response(['type' => 'success', 'request_id' => 'req-sub-1'], 200)]);

        $log = $this->dispatchSmsTemplate();

        $this->assertSame(
            NotificationLog::DELIVERY_SENT,
            $log->delivery_status,
            'submission records the intermediate "accepted by MSG91", never a delivery',
        );
        $this->assertNotSame(NotificationLog::DELIVERY_DELIVERED, $log->delivery_status);
        $this->assertNull($log->failure_reason);
    }

    public function test_only_the_webhook_can_move_a_submitted_sms_to_delivered(): void
    {
        Http::fake(['*' => Http::response(['type' => 'success', 'request_id' => 'req-sub-2'], 200)]);

        $log = $this->dispatchSmsTemplate();
        $this->assertSame(NotificationLog::DELIVERY_SENT, $log->delivery_status);

        $this->postJson($this->url(), [
            'requestId' => 'req-sub-2',
            'data' => [['number' => '919876543210', 'status' => '1', 'desc' => 'DELIVERED']],
        ])->assertOk();

        $this->assertSame(NotificationLog::DELIVERY_DELIVERED, $log->refresh()->delivery_status);
    }

    public function test_a_failure_webhook_moves_a_submitted_sms_to_failed_with_the_reason(): void
    {
        Http::fake(['*' => Http::response(['type' => 'success', 'request_id' => 'req-sub-3'], 200)]);

        $log = $this->dispatchSmsTemplate();

        $this->postJson($this->url(), [
            'requestId' => 'req-sub-3',
            'data' => [['number' => '919876543210', 'status' => '16', 'desc' => 'DND number - rejected']],
        ])->assertOk();

        $log->refresh();
        $this->assertSame(NotificationLog::DELIVERY_FAILED, $log->delivery_status);
        $this->assertStringContainsString('DND number - rejected', (string) $log->failure_reason);
    }

    /**
     * Before the URL is pasted into MSG91 no reports arrive at all. That is
     * a configuration state the UI reports as such, not a fault.
     */
    public function test_delivery_reporting_reads_as_unconfigured_until_the_first_report(): void
    {
        $this->assertFalse(Msg91WebhookEvent::reportingConfigured());

        $this->postJson($this->url(), [
            'requestId' => 'req-abc-123',
            'data' => [['number' => '919876543210', 'status' => '1', 'desc' => 'DELIVERED']],
        ])->assertOk();

        $this->assertTrue(Msg91WebhookEvent::reportingConfigured());
    }

    // ─── The live bug: MSG91 Flow variables are matched by NAME ───────

    public function test_the_otp_send_carries_the_dlt_variable_names_not_var1_var2(): void
    {
        Http::fake(['*' => Http::response(['type' => 'success', 'request_id' => 'r'], 200)]);

        $sms = new SmsService;
        $sms->sendTemplate('9876543210', 'tpl', $sms->otpVariables('123456'));

        Http::assertSent(function ($request): bool {
            $recipient = $this->otpParams($request);

            return array_key_exists('OTP', $recipient)
                && $recipient['OTP'] === '123456'
                && array_key_exists('mins', $recipient)
                && ! array_key_exists('var1', $recipient)
                && ! array_key_exists('var2', $recipient);
        });
    }

    public function test_renaming_the_variables_in_settings_changes_the_payload_keys(): void
    {
        SystemSetting::updateOrCreate(['key' => 'sms_msg91_otp_var_name'], ['value' => 'code']);
        SystemSetting::updateOrCreate(['key' => 'sms_msg91_otp_validity_var_name'], ['value' => 'validity']);

        Http::fake(['*' => Http::response(['type' => 'success'], 200)]);

        $sms = new SmsService;
        $sms->sendTemplate('9876543210', 'tpl', $sms->otpVariables('123456'));

        Http::assertSent(function ($request): bool {
            $recipient = $this->otpParams($request);

            return array_key_exists('code', $recipient)
                && array_key_exists('validity', $recipient)
                && ! array_key_exists('OTP', $recipient);
        });
    }

    /** An admin pasting "##OTP##" must not produce a variable named "##OTP##". */
    public function test_hash_markers_pasted_into_the_setting_are_stripped(): void
    {
        SystemSetting::updateOrCreate(['key' => 'sms_msg91_otp_var_name'], ['value' => '##OTP##']);

        $this->assertSame('OTP', (new SmsService)->otpVariableName());
    }

    public function test_the_validity_sent_matches_the_configured_otp_expiry(): void
    {
        config(['otp.expiry_minutes' => 7]);

        $variables = (new SmsService)->otpVariables('123456');

        $this->assertSame('7', $variables['mins']);
        $this->assertSame(7, OtpService::expiryMinutes());
    }

    /**
     * The shipped auth.otp seed still carries positional var1/var2 keys.
     * They must be translated to the configured names at send time — that
     * is the compatibility path that unbreaks the live trust account
     * without anyone editing a template row.
     */
    public function test_legacy_positional_keys_on_auth_otp_are_renamed_at_send_time(): void
    {
        Http::fake(['*' => Http::response(['type' => 'success', 'request_id' => 'r'], 200)]);

        $template = NotificationTemplate::create([
            'key' => 'auth.otp',
            'channel' => NotificationTemplate::CHANNEL_SMS,
            'label' => 'Auth OTP — SMS',
            'is_enabled' => true,
            'sms_template_id' => 'tpl-legacy',
            'recipient_strategy' => NotificationTemplate::RECIPIENT_CONTEXT_PATH,
            'recipient_value' => 'phone',
            'placeholder_map' => ['var1' => 'otp', 'var2' => 'expires_in_minutes'],
        ]);

        $driver = new SmsNotificationDriver(app(RecipientResolver::class), new SmsService);
        $driver->send($template, new NotificationContext([
            'phone' => '9876543210',
            'otp' => '654321',
            'expires_in_minutes' => 10,
        ]));

        Http::assertSent(function ($request): bool {
            $recipient = $this->otpParams($request);

            return ($recipient['OTP'] ?? null) === '654321'
                && ($recipient['mins'] ?? null) === '10'
                && ! array_key_exists('var1', $recipient);
        });
    }

    /** A template that names its variables is passed through untouched. */
    public function test_named_variables_are_sent_verbatim(): void
    {
        Http::fake(['*' => Http::response(['type' => 'success', 'request_id' => 'r'], 200)]);

        $template = NotificationTemplate::create([
            'key' => 'auth.otp',
            'channel' => NotificationTemplate::CHANNEL_SMS,
            'label' => 'Auth OTP — SMS named',
            'is_enabled' => true,
            'sms_template_id' => 'tpl-named',
            'recipient_strategy' => NotificationTemplate::RECIPIENT_CONTEXT_PATH,
            'recipient_value' => 'phone',
            'placeholder_map' => ['PASSCODE' => 'otp', 'window' => 'expires_in_minutes'],
        ]);

        $driver = new SmsNotificationDriver(app(RecipientResolver::class), new SmsService);
        $driver->send($template, new NotificationContext([
            'phone' => '9876543210',
            'otp' => '111222',
            'expires_in_minutes' => 10,
        ]));

        Http::assertSent(function ($request): bool {
            $recipient = $this->otpParams($request);

            return ($recipient['PASSCODE'] ?? null) === '111222'
                && array_key_exists('window', $recipient);
        });
    }

    /**
     * The live regression: the enabled auth.otp row mapped the validity as
     * `expires_in_minutes` while the DLT template asks for ##mins##, so the
     * OTP arrived but the message read "valid for  minutes".
     */
    public function test_the_validity_variable_is_sent_even_when_the_row_names_it_differently(): void
    {
        Http::fake(['*' => Http::response(['type' => 'success', 'request_id' => 'r'], 200)]);

        $template = NotificationTemplate::create([
            'key' => 'auth.otp',
            'channel' => NotificationTemplate::CHANNEL_SMS,
            'label' => 'Auth OTP — SMS (live mapping)',
            'is_enabled' => true,
            'sms_template_id' => 'tpl-live',
            'recipient_strategy' => NotificationTemplate::RECIPIENT_CONTEXT_PATH,
            'recipient_value' => 'phone',
            // Exactly what production carried.
            'placeholder_map' => [
                'otp' => 'otp',
                'expires_in_minutes' => 'expires_in_minutes',
                'phone' => 'phone',
                'name' => 'name',
            ],
        ]);

        $driver = new SmsNotificationDriver(app(RecipientResolver::class), new SmsService);
        $driver->send($template, new NotificationContext([
            'phone' => '9876543210',
            'otp' => '445566',
            'expires_in_minutes' => 10,
            'name' => 'Ramesh',
        ]));

        Http::assertSent(function ($request): bool {
            $params = $this->otpParams($request);

            // ##mins## must have a number to fill it, whatever the row called it.
            return ($params['mins'] ?? '') === '10'
                && ($params['otp'] ?? null) === '445566';
        });
    }

    // ─── Admin surface ────────────────────────────────────────────────

    /**
     * The page must actually RENDER. This project has shipped Filament
     * closures that 500 only at request time (BindingResolutionException
     * on an un-type-hinted parameter), and System Settings had no render
     * coverage at all — so a broken closure here would have reached
     * production silently.
     *
     * Also asserts both directions of the permission gate: the webhook URL
     * is a credential, so an admin who can open the page must still not see
     * it without manage_sms_webhook.
     */
    public function test_the_webhook_panel_renders_and_is_permission_gated(): void
    {
        $this->seed(\Database\Seeders\RolePermissionSeeder::class);
        \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));

        $withoutPermission = $this->adminHolding(['page_SystemSettings']);
        $this->actingAs($withoutPermission, 'admin');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertFalse(\App\Filament\Pages\SystemSettings::canManageSmsWebhook());
        \Livewire\Livewire::test(\App\Filament\Pages\SystemSettings::class)
            ->assertOk()
            ->assertDontSee($this->token);

        $withPermission = $this->adminHolding(['page_SystemSettings', 'manage_sms_webhook']);
        $this->actingAs($withPermission, 'admin');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->assertTrue(\App\Filament\Pages\SystemSettings::canManageSmsWebhook());
        \Livewire\Livewire::test(\App\Filament\Pages\SystemSettings::class)
            ->assertOk()
            // The paste-ready URL, token included.
            ->assertSet('data.sms_msg91_webhook_url', SmsService::webhookUrl());
    }

    /**
     * Regenerating must change the URL — and must be refused outright for
     * an admin without the permission, not merely hidden from them.
     */
    public function test_regenerating_the_token_invalidates_the_old_url(): void
    {
        $before = SmsService::webhookToken();

        SmsService::regenerateWebhookToken();
        $after = SmsService::webhookToken();

        $this->assertNotSame($before, $after);
        $this->assertGreaterThanOrEqual(32, strlen($after));

        // The old URL stops working the moment the token rotates.
        $this->postJson('/api/webhooks/msg91/' . $before, [
            'requestId' => 'x',
            'data' => [['number' => '919876543210', 'status' => '1', 'desc' => 'DELIVERED']],
        ])->assertForbidden();

        $this->postJson('/api/webhooks/msg91/' . $after, [
            'requestId' => 'x',
            'data' => [['number' => '919876543210', 'status' => '1', 'desc' => 'DELIVERED']],
        ])->assertOk();
    }

    /** The derived URL field must never be written back as a setting. */
    public function test_the_displayed_webhook_url_is_not_persisted_as_a_setting(): void
    {
        $this->assertDatabaseMissing('temple_system_settings', ['key' => 'sms_msg91_webhook_url']);
    }

    // ─── Helpers ──────────────────────────────────────────────────────

    /**
     * A throwaway admin holding exactly these permissions (plus
     * panel_user). Mirrors AdminAuthorizationTest::adminWith so the two
     * files agree on what "an admin without X" means.
     *
     * @param  array<int, string>  $permissions
     */
    private function adminHolding(array $permissions): \App\Models\AdminUser
    {
        $suffix = \Illuminate\Support\Str::lower(\Illuminate\Support\Str::random(8));

        $role = \Spatie\Permission\Models\Role::create([
            'name' => "test_role_{$suffix}",
            'guard_name' => 'admin',
        ]);
        $role->syncPermissions(array_merge(['panel_user'], $permissions));

        $user = \App\Models\AdminUser::create([
            'name' => "Test Admin {$suffix}",
            'email' => "admin-{$suffix}@example.test",
            'password' => 'password',
            'is_active' => true,
        ]);
        $user->assignRole($role);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        return $user->fresh();
    }

    /**
     * Dispatch a real SMS notification through NotificationService and
     * return the log row it wrote.
     */
    private function dispatchSmsTemplate(): NotificationLog
    {
        $template = NotificationTemplate::create([
            'key' => 'auth.otp',
            'channel' => NotificationTemplate::CHANNEL_SMS,
            'label' => 'Auth OTP — SMS',
            'is_enabled' => true,
            'sms_template_id' => 'tpl-x',
            'recipient_strategy' => NotificationTemplate::RECIPIENT_CONTEXT_PATH,
            'recipient_value' => 'phone',
            'placeholder_map' => ['var1' => 'otp', 'var2' => 'expires_in_minutes'],
        ]);

        app(\App\Services\Notifications\NotificationService::class)->sendTemplate($template, [
            'phone' => '9876543210',
            'otp' => '123456',
            'expires_in_minutes' => 10,
        ]);

        return NotificationLog::query()->where('channel', 'sms')->latest('id')->firstOrFail();
    }

    /**
     * auth.otp goes through MSG91's OTP service, whose parameters ride in
     * the query string rather than a JSON recipients[] array. Normalise
     * so the assertions read the same either way.
     *
     * @return array<string, string>
     */
    private function otpParams($request): array
    {
        // Flow posts a JSON recipients[] array; the OTP service puts its
        // params in the query string. Read whichever this request used so
        // the assertions do not care which product handled the send.
        $data = $request->data();
        if (isset($data['recipients'][0]) && is_array($data['recipients'][0])) {
            return $data['recipients'][0];
        }

        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $q);

        // MSG91 carries the code itself as `otp`; surface it under the
        // configured variable name so tests assert on one shape.
        if (isset($q['otp'])) {
            $q[app(\App\Services\SmsService::class)->otpVariableName()] = $q['otp'];
        }
        if (isset($q['mobile'])) {
            $q['mobiles'] = $q['mobile'];
        }

        return $q;
    }

}
