<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\SystemSetting;
use App\Support\YouTubeLive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The temple's live URL is a channel-style /@handle/live link that never
 * changes — resolving it to the CURRENT video id is what makes the stream
 * play in-app and on the website instead of bouncing people to YouTube.
 *
 * Scraping that page works locally but is bot-blocked from the production
 * VPS, so these tests pin the Data API path: the cheap uploads walk, the
 * expensive search fallback and its cooldown, and the keyless scrape.
 */
class YouTubeLiveResolutionTest extends TestCase
{
    use RefreshDatabase;

    private const HANDLE_URL = 'https://www.youtube.com/@Patadiyahanumanjiofficial/live';

    private const CHANNEL = 'UCq5gv3ljQuuHuDYNkDiPEnw';

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    private function withApiKey(): void
    {
        SystemSetting::updateOrCreate(['key' => 'youtube_api_key'], ['value' => 'test-key', 'group' => 'general']);
    }

    /** @param array<string,mixed> $items */
    private function uploadsResponse(array $ids): array
    {
        return ['items' => array_map(fn (string $id) => ['contentDetails' => ['videoId' => $id]], $ids)];
    }

    public function test_a_direct_watch_url_needs_no_lookup(): void
    {
        Http::fake();

        $this->assertSame('QoIfmDbN-oE', YouTubeLive::resolveVideoId('https://www.youtube.com/watch?v=QoIfmDbN-oE'));
        Http::assertNothingSent();
    }

    public function test_channel_url_resolves_through_the_uploads_playlist(): void
    {
        $this->withApiKey();

        Http::fake([
            '*/youtube/v3/playlistItems*' => Http::response($this->uploadsResponse(['oldVideo123', 'liveVideo01'])),
            '*/youtube/v3/videos*' => Http::response(['items' => [
                ['id' => 'oldVideo123', 'snippet' => ['liveBroadcastContent' => 'none']],
                ['id' => 'liveVideo01', 'snippet' => ['liveBroadcastContent' => 'live']],
            ]]),
        ]);

        $this->assertSame('liveVideo01', YouTubeLive::resolveVideoId(self::HANDLE_URL, self::CHANNEL));

        // Two units, and never the 100-unit search.
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/youtube/v3/search'));
    }

    public function test_uploads_playlist_is_derived_from_the_channel_id(): void
    {
        $this->withApiKey();
        Http::fake([
            '*/youtube/v3/playlistItems*' => Http::response($this->uploadsResponse(['liveVideo01'])),
            '*/youtube/v3/videos*' => Http::response(['items' => [
                ['id' => 'liveVideo01', 'snippet' => ['liveBroadcastContent' => 'live']],
            ]]),
        ]);

        YouTubeLive::resolveVideoId(self::HANDLE_URL, self::CHANNEL);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/playlistItems')
            && str_contains($request->url(), 'playlistId=UU'.substr(self::CHANNEL, 2)));
    }

    public function test_a_handle_is_resolved_to_a_channel_id_when_the_setting_is_not_one(): void
    {
        $this->withApiKey();

        Http::fake([
            '*/youtube/v3/channels*' => Http::response(['items' => [['id' => self::CHANNEL]]]),
            '*/youtube/v3/playlistItems*' => Http::response($this->uploadsResponse(['liveVideo01'])),
            '*/youtube/v3/videos*' => Http::response(['items' => [
                ['id' => 'liveVideo01', 'snippet' => ['liveBroadcastContent' => 'live']],
            ]]),
        ]);

        // 'salangpurhanumanji' is what production actually held: not a UC…
        // id, so it must be ignored rather than probed.
        $this->assertSame('liveVideo01', YouTubeLive::resolveVideoId(self::HANDLE_URL, 'salangpurhanumanji'));

        Http::assertSent(fn ($request) => str_contains($request->url(), '/youtube/v3/channels')
            && str_contains(urldecode($request->url()), 'forHandle=@Patadiyahanumanjiofficial'));
    }

    public function test_search_is_the_fallback_when_uploads_has_no_live_video(): void
    {
        $this->withApiKey();

        Http::fake([
            '*/youtube/v3/playlistItems*' => Http::response($this->uploadsResponse(['oldVideo123'])),
            '*/youtube/v3/videos*' => Http::response(['items' => [
                ['id' => 'oldVideo123', 'snippet' => ['liveBroadcastContent' => 'none']],
            ]]),
            '*/youtube/v3/search*' => Http::response(['items' => [['id' => ['videoId' => 'searchLive1']]]]),
        ]);

        $this->assertSame('searchLive1', YouTubeLive::resolveVideoId(self::HANDLE_URL, self::CHANNEL));
    }

    public function test_the_hundred_unit_search_is_rate_limited(): void
    {
        $this->withApiKey();

        Http::fake([
            '*/youtube/v3/playlistItems*' => Http::response($this->uploadsResponse(['oldVideo123'])),
            '*/youtube/v3/videos*' => Http::response(['items' => [
                ['id' => 'oldVideo123', 'snippet' => ['liveBroadcastContent' => 'none']],
            ]]),
            '*/youtube/v3/search*' => Http::response(['items' => []]),
        ]);

        // Nothing live: the first miss may spend a search, later misses in
        // the same window must not — that's what protects the daily quota
        // across a long darshan window with no stream running.
        YouTubeLive::resolveVideoId(self::HANDLE_URL, self::CHANNEL);
        Cache::forget('youtube_live_video_id:'.sha1(self::HANDLE_URL.'|'.self::CHANNEL));
        YouTubeLive::resolveVideoId(self::HANDLE_URL, self::CHANNEL);

        $searches = 0;
        Http::assertSent(function ($request) use (&$searches) {
            if (str_contains($request->url(), '/youtube/v3/search')) {
                $searches++;
            }

            return true;
        });
        $this->assertSame(1, $searches);
    }

    public function test_a_resolved_id_is_cached_rather_than_re_looked_up(): void
    {
        $this->withApiKey();

        Http::fake([
            '*/youtube/v3/playlistItems*' => Http::response($this->uploadsResponse(['liveVideo01'])),
            '*/youtube/v3/videos*' => Http::response(['items' => [
                ['id' => 'liveVideo01', 'snippet' => ['liveBroadcastContent' => 'live']],
            ]]),
        ]);

        YouTubeLive::resolveVideoId(self::HANDLE_URL, self::CHANNEL);
        YouTubeLive::resolveVideoId(self::HANDLE_URL, self::CHANNEL);

        Http::assertSentCount(2);
    }

    public function test_without_a_key_it_falls_back_to_the_page_scrape(): void
    {
        Http::fake([
            'www.youtube.com/*' => Http::response(
                '<link rel="canonical" href="https://www.youtube.com/watch?v=scrapedVid1">'
            ),
        ]);

        $this->assertSame('scrapedVid1', YouTubeLive::resolveVideoId(self::HANDLE_URL, self::CHANNEL));
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'googleapis.com'));
    }

    public function test_a_bot_blocked_scrape_resolves_to_null(): void
    {
        // What the production VPS actually receives: no canonical, no data.
        Http::fake([
            'www.youtube.com/*' => Http::response('<link rel="canonical" href="undefined"><title> - YouTube</title>'),
        ]);

        $this->assertNull(YouTubeLive::resolveVideoId(self::HANDLE_URL, self::CHANNEL));
    }

    public function test_an_api_failure_does_not_blow_up_the_page(): void
    {
        $this->withApiKey();

        Http::fake([
            'googleapis.com/*' => Http::response(['error' => ['errors' => [['reason' => 'quotaExceeded']]]], 403),
            'www.youtube.com/*' => Http::response('<link rel="canonical" href="undefined">'),
        ]);

        $this->assertNull(YouTubeLive::resolveVideoId(self::HANDLE_URL, self::CHANNEL));
    }
}
