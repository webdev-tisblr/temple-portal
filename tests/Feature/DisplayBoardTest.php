<?php

namespace Tests\Feature;

use App\Models\Donation;
use App\Models\DonationBoardEntry;
use App\Models\SystemSetting;
use App\Services\DisplayBoardService;
use App\Services\PaymentCaptureService;
use Database\Factories\DevoteeFactory;
use Database\Factories\DonationFactory;
use Database\Factories\PaymentFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Live donor display board (2026-08-13) — the screen in the temple hall.
 *
 * These tests are ordered by what would actually hurt if it broke. The first
 * two are the reason the feature is risky at all: a public screen that names a
 * Gupt Daan donor, or prints 500 characters of unreviewed donor free text, is
 * a harm you cannot take back once the hall has seen it.
 */
class DisplayBoardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SystemSetting::updateOrCreate(['key' => 'board_enabled'], ['value' => '1', 'group' => 'app']);
        SystemSetting::forgetCache();
    }

    private function token(): string
    {
        return app(DisplayBoardService::class)->accessToken();
    }

    /** A captured donation, taken through the real capture path. */
    private function capture(array $donation = [], array $devotee = []): Donation
    {
        $dev = DevoteeFactory::new()->create($devotee);
        $payment = PaymentFactory::new()->create(['status' => 'created']);

        $d = DonationFactory::new()->create(array_merge([
            'devotee_id' => $dev->id,
            'payment_id' => $payment->id,
            'amount' => 11000,
        ], $donation));

        app(PaymentCaptureService::class)->markCaptured($payment, null, 'cash');

        return $d->fresh();
    }

    /**
     * 1. THE ONE THAT MATTERS MOST.
     *
     * A Gupt Daan donor's real name and city must appear NOWHERE — not in the
     * stored row, not in the feed body. The substring assertion over the whole
     * serialized payload is deliberate: it keeps catching this if someone later
     * adds a field to the snapshot without thinking about masking.
     */
    public function test_an_anonymous_donor_is_never_named_anywhere(): void
    {
        $this->capture(
            ['anonymous' => true],
            ['name' => 'ZZSECRETDONORZZ', 'city' => 'ZZSECRETCITYZZ'],
        );

        $entry = DonationBoardEntry::firstOrFail();

        $this->assertStringNotContainsString('ZZSECRETDONORZZ', json_encode($entry->payload));
        $this->assertStringNotContainsString('ZZSECRETCITYZZ', json_encode($entry->payload));
        $this->assertSame(__('projects.gupt_daan_name'), $entry->payload['name']);
        $this->assertSame('', $entry->payload['city']);

        // ...and not through the wire either.
        $body = $this->getJson('/api/v1/board/feed?since=0&token='.$this->token())
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('ZZSECRETDONORZZ', $body);
        $this->assertStringNotContainsString('ZZSECRETCITYZZ', $body);
    }

    /**
     * 2. Anonymous gifts are not merely masked — they are never queued for a
     * full-screen takeover, because in a hall the timing itself identifies the
     * donor who just left the counter.
     */
    public function test_an_anonymous_gift_is_not_announced_but_still_honoured(): void
    {
        $this->capture(['anonymous' => true]);
        $this->travel(10)->seconds();

        $data = $this->getJson('/api/v1/board/feed?since=0&token='.$this->token())
            ->assertOk()
            ->json();

        $this->assertSame([], $data['entries'], 'Gupt Daan must never take over the screen');

        // The recent COLUMN is ordered newest-first, which makes it a
        // timeline: a masked row at position one, moments after someone left
        // the counter, identifies them as surely as their name would. So
        // anonymous gifts are kept out of it entirely...
        $this->assertSame([], $data['recent'], 'the ordered column must be named gifts only');

        // ...and honoured in the shuffled, order-free roll instead.
        $this->assertCount(1, $data['anonymous_recent']);
        $this->assertSame(__('projects.gupt_daan_name'), $data['anonymous_recent'][0]['name']);
        $this->assertArrayNotHasKey(
            'seq',
            $data['anonymous_recent'][0],
            'no sequence number, or the ordering leaks back out',
        );
    }

    /** A named gift DOES belong in the ordered column, newest first. */
    public function test_named_gifts_appear_in_the_ordered_recent_column(): void
    {
        $this->capture([], ['name' => 'First Donor']);
        $this->capture([], ['name' => 'Second Donor']);
        $this->travel(10)->seconds();

        $data = $this->getJson('/api/v1/board/feed?since=0&token='.$this->token())
            ->assertOk()->json();

        $this->assertCount(2, $data['recent']);
        $this->assertSame('Second Donor', $data['recent'][0]['name'], 'newest first');
        $this->assertSame([], $data['anonymous_recent']);
    }

    /** 3. Donor free text must never reach a screen in front of the congregation. */
    public function test_donor_entered_purpose_never_leaks_to_the_board(): void
    {
        $this->capture(['purpose' => 'ZZTOPSECRETPURPOSEZZ']);
        $this->travel(10)->seconds();

        $body = $this->getJson('/api/v1/board/feed?since=0&token='.$this->token())
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('ZZTOPSECRETPURPOSEZZ', $body);
        $this->assertStringNotContainsString(
            'ZZTOPSECRETPURPOSEZZ',
            json_encode(DonationBoardEntry::firstOrFail()->payload),
        );
    }

    /**
     * 4. THE LAUNCH-DAY SILENT KILLER.
     *
     * coming_soon_mode is 1 on production right now. Without 'board' in
     * ComingSoonMode::BYPASS_PATHS the hall screen serves the 503 page all
     * evening and nobody finds out until the event.
     */
    public function test_the_board_survives_coming_soon_mode(): void
    {
        SystemSetting::updateOrCreate(['key' => 'coming_soon_mode'], ['value' => '1', 'group' => 'general']);
        SystemSetting::forgetCache();
        cache()->forget('system.coming_soon_mode');

        $this->get('/board?token='.$this->token())->assertOk();
        $this->getJson('/api/v1/board/feed?token='.$this->token())->assertOk();

        // Sanity: the gate really is on — a normal page is hidden.
        $this->get('/halls')->assertStatus(503);
    }

    /**
     * 5. Cursor discipline: never replay, never skip, and a cold start must not
     * dump the whole day onto the screen as takeovers on every browser refresh.
     */
    public function test_cursor_never_replays_and_a_cold_start_announces_nothing(): void
    {
        $this->capture();
        $this->capture();
        $this->travel(10)->seconds();

        // Cold start (no `since`) → seed only.
        $cold = $this->getJson('/api/v1/board/feed?token='.$this->token())->assertOk()->json();
        $this->assertSame([], $cold['entries'], 'a refresh must never replay the day');
        $this->assertGreaterThan(0, $cold['latest_seq']);

        // From the beginning → both, ascending.
        $all = $this->getJson('/api/v1/board/feed?since=0&token='.$this->token())->assertOk()->json();
        $this->assertCount(2, $all['entries']);
        $this->assertLessThan($all['entries'][1]['seq'], $all['entries'][0]['seq']);

        // Caught up → nothing further, and no drift in latest_seq.
        $caught = $this->getJson('/api/v1/board/feed?since='.$all['latest_seq'].'&token='.$this->token())
            ->assertOk()->json();
        $this->assertSame([], $caught['entries']);
        $this->assertSame($all['latest_seq'], $caught['latest_seq']);
    }

    /**
     * 6. HIGHEST SEVERITY. A decorative screen must never be able to fail a
     * real donor's payment. Bind a board that always throws and assert the
     * money path is completely unaffected.
     */
    public function test_a_broken_board_can_never_break_a_payment_capture(): void
    {
        $this->app->bind(DisplayBoardService::class, function () {
            return new class extends DisplayBoardService
            {
                public function announce(Donation $donation): ?DonationBoardEntry
                {
                    throw new \RuntimeException('board is on fire');
                }
            };
        });

        $payment = PaymentFactory::new()->create(['status' => 'created']);
        DonationFactory::new()->create([
            'payment_id' => $payment->id,
            'devotee_id' => DevoteeFactory::new()->create()->id,
        ]);

        app(PaymentCaptureService::class)->markCaptured($payment, null, 'cash');

        $this->assertSame('captured', $payment->fresh()->status->value);
        $this->assertSame(0, DonationBoardEntry::count());
    }

    /** 7. The visibility lag exists so an out-of-order commit can't be skipped. */
    public function test_entries_are_withheld_until_their_visibility_lag_passes(): void
    {
        $this->capture();

        // Immediately after capture the row exists but is not yet servable.
        $this->assertSame(1, DonationBoardEntry::count());
        $fresh = $this->getJson('/api/v1/board/feed?since=0&token='.$this->token())->assertOk()->json();
        $this->assertSame([], $fresh['entries']);

        $this->travel(10)->seconds();

        $later = $this->getJson('/api/v1/board/feed?since=0&token='.$this->token())->assertOk()->json();
        $this->assertCount(1, $later['entries']);
    }

    /** 8. Replayed captures (webhook + client verify) must announce once. */
    public function test_announcing_is_idempotent(): void
    {
        $payment = PaymentFactory::new()->create(['status' => 'created']);
        DonationFactory::new()->create([
            'payment_id' => $payment->id,
            'devotee_id' => DevoteeFactory::new()->create()->id,
        ]);

        app(PaymentCaptureService::class)->markCaptured($payment, null, 'cash');
        app(PaymentCaptureService::class)->markCaptured($payment, null, 'cash');

        $this->assertSame(1, DonationBoardEntry::count());
    }

    /** 9. The feed is not an open donor database. */
    public function test_the_feed_and_page_are_token_gated(): void
    {
        // 404 not 401 — don't confirm the endpoint exists to a prober.
        $this->getJson('/api/v1/board/feed')->assertNotFound();
        $this->getJson('/api/v1/board/feed?token=wrong')->assertNotFound();
        $this->get('/board')->assertNotFound();
        $this->get('/board?token=wrong')->assertNotFound();
    }

    /** 10. Kill switch and retroactive takedown. */
    public function test_kill_switch_and_suppression(): void
    {
        $this->capture();
        $this->travel(10)->seconds();

        $entry = DonationBoardEntry::firstOrFail();
        $entry->update(['suppressed_at' => now()]);

        $data = $this->getJson('/api/v1/board/feed?since=0&token='.$this->token())->assertOk()->json();
        $this->assertSame([], $data['entries']);
        $this->assertContains($entry->id, $data['suppressed_ids'], 'the screen must be told to pull it');

        SystemSetting::updateOrCreate(['key' => 'board_enabled'], ['value' => '0', 'group' => 'app']);
        SystemSetting::forgetCache();

        $off = $this->getJson('/api/v1/board/feed?since=0&token='.$this->token())->assertOk()->json();
        $this->assertFalse($off['enabled']);
        $this->assertSame([], $off['recent']);
    }

    /** 11. Neither cache layer may ever hold a board response. */
    public function test_neither_cache_layer_can_hold_the_board(): void
    {
        foreach (['/board?token=', '/api/v1/board/feed?token='] as $path) {
            $response = $this->get($path.$this->token())->assertOk();

            // Assert the DIRECTIVES, not an exact string: Symfony normalises
            // Cache-Control (reorders alphabetically, adds `private`), so an
            // equality assertion here would be testing the framework's
            // formatting rather than the behaviour that matters.
            $cacheControl = $response->headers->get('Cache-Control');
            foreach (['no-store', 'no-cache', 'must-revalidate', 'max-age=0'] as $directive) {
                $this->assertStringContainsString($directive, $cacheControl, $path);
            }

            // The one Cloudflare actually obeys.
            $response->assertHeader('CDN-Cache-Control', 'no-store');
        }
    }
}
