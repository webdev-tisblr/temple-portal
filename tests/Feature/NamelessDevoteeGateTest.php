<?php

namespace Tests\Feature;

use App\Models\Devotee;
use App\Models\OtpCode;
use App\Services\Notifications\NotificationContext;
use App\Services\Notifications\Drivers\WhatsAppNotificationDriver;
use Database\Factories\DevoteeFactory;
use Database\Factories\HallFactory;
use Database\Factories\SevaFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A devotee with no name on file (2026-08-21).
 *
 * OTP signup verifies a PHONE, and the row is created with `name => ''` —
 * there is nothing else to put there yet. What was missing until this test
 * existed was anything that then INSISTED on the name: the website asked
 * for it and the app pointed at its profile screen, but the API never
 * checked, and the app's profile screen had a home button on it. So
 * accounts transacted with no name, and because Meta rejects a template
 * message whose parameter resolves to an empty string, those devotees got
 * no confirmation, no receipt and no greeting card at all.
 *
 * Covers the three layers that now stop it:
 *   1. the API refuses the transactional endpoints (422 profile_incomplete)
 *   2. the web login/handoff holds them on /profile/complete
 *   3. the WhatsApp driver never emits an empty parameter, whatever leaks
 *
 * MySQL-only project: requires the `temple_portal_test` database
 * (see phpunit.xml / CLAUDE.md).
 */
class NamelessDevoteeGateTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '9876500099';

    private function nameless(): Devotee
    {
        return DevoteeFactory::new()->create([
            'phone' => self::PHONE,
            'name' => '',
        ]);
    }

    private function issueOtp(string $phone = self::PHONE): string
    {
        OtpCode::create([
            'phone' => $phone,
            'code' => '123456',
            'purpose' => 'login',
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);

        return '123456';
    }

    // ── 1. the API gate ───────────────────────────────────────────────

    public function test_api_refuses_transactions_from_a_nameless_devotee(): void
    {
        // Real seva/hall rows: route-model binding runs BEFORE this gate,
        // so a made-up id would 404 and prove nothing about the gate.
        $seva = SevaFactory::new()->create();
        $hall = HallFactory::new()->create();

        $endpoints = [
            'donation' => '/api/v1/donations',
            'seva booking' => "/api/v1/sevas/{$seva->id}/book",
            'store order' => '/api/v1/store/orders',
            'hall booking' => "/api/v1/halls/{$hall->id}/book",
            'contact form' => '/api/v1/contact',
            'ios donate handoff' => '/api/v1/auth/web-session-token',
        ];

        Sanctum::actingAs($this->nameless(), ['*'], 'sanctum');

        foreach ($endpoints as $label => $uri) {
            $response = $this->postJson($uri, []);

            $this->assertSame(422, $response->status(), "{$label} should be refused");
            $this->assertSame('profile_incomplete', $response->json('code'), "{$label} should say why");
        }
    }

    public function test_a_named_devotee_passes_the_gate(): void
    {
        $devotee = DevoteeFactory::new()->create(['name' => 'Hari Patel']);
        Sanctum::actingAs($devotee, ['*'], 'sanctum');

        // Reaches the controller, so it fails on the REQUEST body instead —
        // any answer other than profile_incomplete proves the gate opened.
        $response = $this->postJson('/api/v1/donations', []);

        $this->assertNotSame('profile_incomplete', $response->json('code'));
    }

    /**
     * Reading is not transacting. Browsing must stay open, or the gate
     * would trap a devotee on a blank app instead of on the profile form.
     */
    public function test_read_endpoints_are_not_gated(): void
    {
        Sanctum::actingAs($this->nameless(), ['*'], 'sanctum');

        $this->getJson('/api/v1/me')->assertOk();
        $this->getJson('/api/v1/donations/history')->assertOk();
    }

    /** /me PUT is the ONLY way to fix this — gating it would deadlock. */
    public function test_the_devotee_can_still_save_their_name(): void
    {
        $devotee = $this->nameless();
        Sanctum::actingAs($devotee, ['*'], 'sanctum');

        $this->putJson('/api/v1/me', ['name' => 'Hari Patel'])->assertOk();

        $this->assertSame('Hari Patel', $devotee->fresh()->name);
    }

    public function test_the_resource_reports_profile_completeness(): void
    {
        Sanctum::actingAs($this->nameless(), ['*'], 'sanctum');
        $this->getJson('/api/v1/me')->assertJsonPath('data.profile_complete', false);

        Sanctum::actingAs(DevoteeFactory::new()->create(['name' => 'Hari']), ['*'], 'sanctum');
        $this->getJson('/api/v1/me')->assertJsonPath('data.profile_complete', true);
    }

    // ── 2. the web gate ───────────────────────────────────────────────

    public function test_web_login_holds_a_nameless_devotee_on_the_profile_form(): void
    {
        $this->nameless();

        $this->post('/login/otp/verify', [
            'phone' => self::PHONE,
            'code' => $this->issueOtp(),
        ])->assertRedirect(route('profile.complete'));
    }

    public function test_a_nameless_devotee_cannot_reach_a_protected_page(): void
    {
        $this->actingAs($this->nameless(), 'devotee')
            ->get('/dashboard')
            ->assertRedirect(route('profile.complete'));
    }

    /**
     * The interstitial asks for the NAME and nothing else (2026-08-21).
     * It briefly demanded the full address as well, which is what people
     * abandoned — and an abandoned signup is a nameless account.
     */
    public function test_the_profile_form_accepts_a_name_on_its_own(): void
    {
        $devotee = $this->nameless();

        $this->actingAs($devotee, 'devotee')
            ->post(route('profile.complete.save'), ['name' => 'Hari Patel'])
            ->assertSessionHasNoErrors()
            ->assertRedirect();

        $this->assertSame('Hari Patel', $devotee->fresh()->name);
    }

    /** Blank is allowed; WRONG is not — a bad pincode breaks despatch. */
    public function test_the_profile_form_still_rejects_a_malformed_pincode(): void
    {
        $this->actingAs($this->nameless(), 'devotee')
            ->post(route('profile.complete.save'), ['name' => 'Hari Patel', 'pincode' => '12'])
            ->assertSessionHasErrors('pincode');
    }

    // ── 3. the WhatsApp parameter guard ───────────────────────────────

    /**
     * The failure this whole batch exists to stop: a blank name used to be
     * handed to Meta as "", which rejects the ENTIRE message.
     */
    public function test_a_blank_name_parameter_never_reaches_meta_empty(): void
    {
        $components = WhatsAppNotificationDriver::buildComponents(
            [[
                'type' => 'body',
                'parameters' => [
                    ['type' => 'text', 'value_token' => 'devotee_name'],
                    ['type' => 'text', 'value_token' => 'amount'],
                ],
            ]],
            ['devotee_name' => 'devotee.name', 'amount' => 'donation.amount'],
            new NotificationContext([
                'devotee' => ['name' => '', 'language' => 'gu'],
                'donation' => ['amount' => 501],
            ]),
        );

        $params = $components[0]['parameters'];

        $this->assertSame('ભક્ત', $params[0]['text'], 'a person name falls back to the respectful generic word');
        $this->assertSame('501', $params[1]['text']);

        foreach ($params as $param) {
            $this->assertNotSame('', $param['text']);
        }
    }

    public function test_a_blank_non_name_parameter_falls_back_to_a_dash(): void
    {
        $components = WhatsAppNotificationDriver::buildComponents(
            [['type' => 'body', 'parameters' => [['type' => 'text', 'value_token' => 'seva_name']]]],
            ['seva_name' => 'booking.seva_name'],
            new NotificationContext(['devotee' => ['language' => 'en']]),
        );

        $this->assertSame('-', $components[0]['parameters'][0]['text']);
    }
}
