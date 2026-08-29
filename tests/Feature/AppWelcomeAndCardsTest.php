<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Devotee;
use App\Models\NotificationTemplate;
use App\Models\SystemSetting;
use Database\Factories\DevoteeFactory;
use Database\Factories\DonationFactory;
use Database\Factories\PaymentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Three app-facing additions from the 2026-08-29 batch:
 *
 *   • the WELCOME notification now hangs off device-token registration rather
 *     than OTP verification, because the older trigger fires before the app
 *     has told us which device to push to — a push template on it could never
 *     be delivered;
 *   • greeting cards are listed in the app, so a WhatsApp message Meta
 *     rejected is no longer the only copy a devotee ever gets;
 *   • force-update can be pinned to the LATEST version, not just the minimum.
 */
class AppWelcomeAndCardsTest extends TestCase
{
    use RefreshDatabase;

    private function registerDevice(Devotee $devotee): TestResponse
    {
        Sanctum::actingAs($devotee);

        return $this->postJson('/api/v1/me/device-tokens', [
            'token' => str_repeat('t', 40),
            'platform' => 'android',
        ]);
    }

    public function test_the_first_device_registration_welcomes_the_devotee_exactly_once(): void
    {
        $devotee = DevoteeFactory::new()->create();
        $devotee->forceFill(['welcomed_at' => null])->save();

        $this->registerDevice($devotee)->assertOk();

        $welcomedAt = $devotee->fresh()->welcomed_at;
        $this->assertNotNull($welcomedAt, 'the first registration must stamp the devotee as welcomed');

        // Opening the app again re-registers the same token. That must not
        // welcome them a second time.
        $this->registerDevice($devotee)->assertOk();

        $this->assertEquals(
            $welcomedAt->timestamp,
            $devotee->fresh()->welcomed_at->timestamp,
            'the welcome is once per devotee, not once per app open',
        );
    }

    public function test_a_devotee_who_predates_the_feature_is_not_welcomed_again(): void
    {
        // The migration back-filled welcomed_at = created_at for everyone who
        // already existed. Without that, the first app open after the deploy
        // would push a welcome message at the entire userbase.
        $devotee = DevoteeFactory::new()->create();
        $stamped = now()->subYear();
        $devotee->forceFill(['welcomed_at' => $stamped])->save();

        $this->registerDevice($devotee)->assertOk();

        $this->assertEquals($stamped->timestamp, $devotee->fresh()->welcomed_at->timestamp);
    }

    public function test_a_push_template_carries_its_deep_link_intent(): void
    {
        // The point of the intent columns: a trigger push used to send a
        // `deep_link` string the app has no handler for, so tapping one always
        // landed on the home screen.
        $template = NotificationTemplate::create([
            'key' => 'devotee.first_login',
            'label' => 'Welcome push',
            'channel' => NotificationTemplate::CHANNEL_PUSH,
            'is_enabled' => true,
            'push_title' => ['gu' => 'જય સિયારામ'],
            'push_body' => ['gu' => 'આપનું સ્વાગત છે'],
            'push_intent' => 'seva-detail',
            'push_intent_params' => ['id' => '42'],
        ]);

        $this->assertSame('seva-detail', $template->fresh()->push_intent);
        $this->assertSame(['id' => '42'], $template->fresh()->push_intent_params);
    }

    public function test_app_config_reports_whether_the_latest_version_is_forced(): void
    {
        $this->getJson('/api/v1/app-config')
            ->assertOk()
            ->assertJsonPath('data.force_latest_version', false);

        SystemSetting::updateOrCreate(['key' => 'app_force_latest_version'], ['value' => '1', 'group' => 'app']);

        $this->getJson('/api/v1/app-config')
            ->assertOk()
            ->assertJsonPath('data.force_latest_version', true);
    }

    public function test_the_app_lists_only_the_callers_own_greeting_cards(): void
    {
        $mine = DevoteeFactory::new()->create();
        $theirs = DevoteeFactory::new()->create();

        $captured = PaymentFactory::new()->create(['status' => 'captured']);
        $ownCard = DonationFactory::new()->create([
            'devotee_id' => $mine->id,
            'payment_id' => $captured->id,
            'greeting_card_path' => 'greeting-cards/mine-gu.png',
        ]);

        $otherPayment = PaymentFactory::new()->create(['status' => 'captured']);
        DonationFactory::new()->create([
            'devotee_id' => $theirs->id,
            'payment_id' => $otherPayment->id,
            'greeting_card_path' => 'greeting-cards/theirs-gu.png',
        ]);

        Sanctum::actingAs($mine);

        $response = $this->getJson('/api/v1/me/greeting-cards')->assertOk();

        $response->assertJsonCount(1, 'data.cards')
            ->assertJsonPath('data.cards.0.id', (string) $ownCard->id)
            ->assertJsonPath('data.cards.0.type', 'donation');

        // Two links, for two jobs: a public one an image widget can load and a
        // devotee can share, and an authenticated one for the download flow.
        // Both regenerate a swept card on demand, so LISTING renders nothing.
        $this->assertStringContainsString('/donate/greeting-card/', $response->json('data.cards.0.preview_url'));
        $this->assertSame(
            '/me/greeting-cards/donation/'.$ownCard->id,
            $response->json('data.cards.0.download_endpoint'),
        );
    }

    public function test_one_devotee_cannot_download_anothers_card(): void
    {
        $mine = DevoteeFactory::new()->create();
        $theirs = DevoteeFactory::new()->create();

        $captured = PaymentFactory::new()->create(['status' => 'captured']);
        $theirCard = DonationFactory::new()->create([
            'devotee_id' => $theirs->id,
            'payment_id' => $captured->id,
            'greeting_card_path' => 'greeting-cards/theirs-gu.png',
        ]);

        Sanctum::actingAs($mine);

        $this->getJson('/api/v1/me/greeting-cards/donation/'.$theirCard->id)->assertNotFound();
    }

    public function test_a_donation_that_was_never_paid_for_has_no_card(): void
    {
        $devotee = DevoteeFactory::new()->create();
        $pending = PaymentFactory::new()->create(['status' => 'created']);

        DonationFactory::new()->create([
            'devotee_id' => $devotee->id,
            'payment_id' => $pending->id,
            'greeting_card_path' => 'greeting-cards/abandoned-gu.png',
        ]);

        Sanctum::actingAs($devotee);

        $this->getJson('/api/v1/me/greeting-cards')
            ->assertOk()
            ->assertJsonCount(0, 'data.cards');
    }
}
