<?php

namespace Tests\Feature;

use App\Models\DonationCampaign;
use App\Models\NotificationTemplate;
use App\Services\Notifications\NotificationRegistry;
use App\Services\PaymentCaptureService;
use Database\Factories\DonationFactory;
use Database\Factories\PaymentFactory;
use Database\Seeders\NotificationTemplatesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Coverage for the 2026-08-09 split (item 5.1): campaign donations fire
 * their own `donation.campaign.confirmed` trigger instead of the generic
 * `donation.confirmed`, so the trust can map campaign-specific
 * WhatsApp / SMS / Email templates.
 *
 * The invariants under test:
 *   (a) a captured donation WITH a campaign fires ONLY the campaign key,
 *   (b) a captured donation WITHOUT a campaign fires ONLY the generic key,
 *   (c) nothing is delivered while the campaign template is disabled —
 *       and the generic message does not leak out in its place,
 *   (d) capturing the same payment twice never double-dispatches.
 *
 * MySQL-only project: requires the `temple_portal_test` database
 * (see phpunit.xml / CLAUDE.md).
 */
class CampaignNotificationTriggerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('r2_private');
        Mail::fake();
    }

    private function service(): PaymentCaptureService
    {
        return app(PaymentCaptureService::class);
    }

    private function campaign(): DonationCampaign
    {
        return DonationCampaign::create([
            'title_gu' => 'મંદિર જીર્ણોદ્ધાર',
            'title_hi' => 'मंदिर जीर्णोद्धार',
            'title_en' => 'Temple Renovation',
            'slug' => 'temple-renovation',
            'goal_amount' => 500000,
            'raised_amount' => 125000,
            'start_date' => now()->subMonth()->toDateString(),
            'is_active' => true,
        ]);
    }

    private function genericTemplate(): NotificationTemplate
    {
        return NotificationTemplate::create([
            'key' => 'donation.confirmed',
            'channel' => 'email',
            'label' => 'Test — generic donation confirmed',
            'is_enabled' => true,
            'subject' => 'Thank you',
            'body' => '<p>{{ donor_name }} — {{ amount }}</p>',
            'recipient_strategy' => NotificationTemplate::RECIPIENT_DEVOTEE,
            'placeholder_map' => [
                'donor_name' => 'devotee.name',
                'amount' => 'donation.amount',
            ],
        ]);
    }

    private function campaignTemplate(bool $enabled): NotificationTemplate
    {
        return NotificationTemplate::create([
            'key' => 'donation.campaign.confirmed',
            'channel' => 'email',
            'label' => 'Test — campaign donation confirmed',
            'is_enabled' => $enabled,
            'subject' => 'Thank you for supporting {{ campaign_title }}',
            'body' => '<p>{{ donor_name }} — {{ campaign_title }} — {{ campaign_url }}</p>',
            'recipient_strategy' => NotificationTemplate::RECIPIENT_DEVOTEE,
            'placeholder_map' => [
                'donor_name' => 'devotee.name',
                'campaign_title' => 'donation.campaign.title_gu',
                'campaign_url' => 'campaign_url',
            ],
        ]);
    }

    private function logCount(string $key): int
    {
        return DB::table('temple_notification_logs')->where('template_key', $key)->count();
    }

    // ── (a) campaign donation → campaign trigger only ────────────────

    public function test_campaign_donation_fires_campaign_trigger_and_not_the_generic_one(): void
    {
        $this->genericTemplate();
        $this->campaignTemplate(enabled: true);

        $payment = PaymentFactory::new()->create();
        DonationFactory::new()->create([
            'payment_id' => $payment->id,
            'campaign_id' => $this->campaign()->id,
            'donation_type' => 'campaign',
        ]);

        $this->service()->markCaptured($payment, 'pay_campaign_1');

        $this->assertSame('captured', $payment->fresh()->status->value);
        $this->assertSame(1, $this->logCount('donation.campaign.confirmed'));
        $this->assertSame(
            0,
            $this->logCount('donation.confirmed'),
            'a campaign donation must NEVER also fire the generic confirmation'
        );
    }

    public function test_campaign_context_exposes_campaign_placeholders(): void
    {
        $this->campaignTemplate(enabled: true);

        $payment = PaymentFactory::new()->create();
        DonationFactory::new()->create([
            'payment_id' => $payment->id,
            'campaign_id' => $this->campaign()->id,
            'donation_type' => 'campaign',
        ]);

        $this->service()->markCaptured($payment, 'pay_campaign_ctx');

        $snapshot = DB::table('temple_notification_logs')
            ->where('template_key', 'donation.campaign.confirmed')
            ->value('context_snapshot');

        $this->assertNotNull($snapshot);
        $this->assertStringContainsString('campaign_url', (string) $snapshot);
        $this->assertStringContainsString('temple-renovation', (string) $snapshot);
        $this->assertStringContainsString('campaign_raised', (string) $snapshot);
    }

    // ── (b) plain donation → generic trigger only ────────────────────

    public function test_donation_without_campaign_fires_the_generic_trigger(): void
    {
        $this->genericTemplate();
        $this->campaignTemplate(enabled: true);

        $payment = PaymentFactory::new()->create();
        DonationFactory::new()->create([
            'payment_id' => $payment->id,
            'campaign_id' => null,
        ]);

        $this->service()->markCaptured($payment, 'pay_plain_1');

        $this->assertSame(1, $this->logCount('donation.confirmed'));
        $this->assertSame(0, $this->logCount('donation.campaign.confirmed'));
    }

    // ── (c) disabled template sends nothing ──────────────────────────

    public function test_disabled_campaign_template_sends_nothing_at_all(): void
    {
        // Exactly the shipped state: the seeder creates the campaign row
        // with is_enabled = false and the admin has not switched it on.
        $this->genericTemplate();
        $this->campaignTemplate(enabled: false);

        $payment = PaymentFactory::new()->create();
        DonationFactory::new()->create([
            'payment_id' => $payment->id,
            'campaign_id' => $this->campaign()->id,
            'donation_type' => 'campaign',
        ]);

        $this->service()->markCaptured($payment, 'pay_campaign_disabled');

        $this->assertSame('captured', $payment->fresh()->status->value);
        $this->assertSame(
            0,
            $this->logCount('donation.campaign.confirmed'),
            'a disabled template must never deliver'
        );
        $this->assertSame(
            0,
            $this->logCount('donation.confirmed'),
            'the generic message must not leak out in the campaign message\'s place'
        );
        Mail::assertNothingSent();
    }

    public function test_campaign_donation_keeps_the_generic_message_until_a_campaign_template_exists(): void
    {
        // Safety valve: with NO donation.campaign.confirmed row at all
        // (pre-seed installs / pre-deploy state) campaign donors must keep
        // receiving the existing generic confirmation rather than silently
        // getting nothing.
        $this->genericTemplate();

        $payment = PaymentFactory::new()->create();
        DonationFactory::new()->create([
            'payment_id' => $payment->id,
            'campaign_id' => $this->campaign()->id,
            'donation_type' => 'campaign',
        ]);

        $this->service()->markCaptured($payment, 'pay_campaign_fallback');

        $this->assertSame(1, $this->logCount('donation.confirmed'));
        $this->assertSame(0, $this->logCount('donation.campaign.confirmed'));
    }

    // ── (d) double capture never double-dispatches ───────────────────

    public function test_double_capture_does_not_double_dispatch_the_campaign_trigger(): void
    {
        $this->genericTemplate();
        $this->campaignTemplate(enabled: true);

        $payment = PaymentFactory::new()->create();
        DonationFactory::new()->create([
            'payment_id' => $payment->id,
            'campaign_id' => $this->campaign()->id,
            'donation_type' => 'campaign',
        ]);

        $service = $this->service();
        $service->markCaptured($payment, 'pay_campaign_dupe');
        // Webhook racing /payments/verify after the first call captured.
        $service->markCaptured($payment->fresh(), 'pay_campaign_dupe');

        $this->assertSame(1, $this->logCount('donation.campaign.confirmed'));
        $this->assertSame(0, $this->logCount('donation.confirmed'));
    }

    // ── registry / seeder wiring ─────────────────────────────────────

    public function test_trigger_is_registered_so_admins_can_pick_it(): void
    {
        $this->assertArrayHasKey(
            'donation.campaign.confirmed',
            NotificationRegistry::all(),
            'a key missing from the registry cannot be selected in admin, so it could never fire'
        );
    }

    public function test_seeder_is_idempotent_and_seeds_the_campaign_template_disabled(): void
    {
        $this->seed(NotificationTemplatesSeeder::class);
        $this->seed(NotificationTemplatesSeeder::class);

        $rows = NotificationTemplate::where('key', 'donation.campaign.confirmed')->get();

        $this->assertCount(1, $rows, 'seeder must be idempotent — it runs on every deploy');
        $this->assertSame('email', $rows->first()->channel);
        $this->assertFalse(
            (bool) $rows->first()->is_enabled,
            'nothing may start sending on its own — the campaign template ships disabled'
        );
    }
}
