<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Resolve the CURRENT live video id for the temple's YouTube stream.
 *
 * Admins paste either a direct video URL (watch?v=… / youtu.be/… /
 * youtube.com/live/…) — trivially parseable — or a channel-style URL
 * (/@handle/live, /channel/…/live) whose current video id changes every
 * stream. The channel-style link is the one worth having: it never needs
 * re-pasting, so the admin sets it once and every stream just works.
 *
 * Resolving it is the hard part. Scraping the /live page for its
 * canonical watch URL works from an ordinary connection but NOT from the
 * production VPS: YouTube serves datacenter IPs a bot-challenge page with
 * `<link rel="canonical" href="undefined">` and no video data (verified
 * 2026-09-05 — the RSS feed 404s and /embed/live_stream reports "offline"
 * mid-stream from there too). So the Data API is the primary path and the
 * scrape is only the keyless fallback for local/dev.
 *
 * Quota discipline (10,000 units/day free): the obvious call —
 * search.list?eventType=live — costs 100 units and would blow the budget
 * when polled through a long darshan window. Instead we walk the
 * channel's uploads playlist (1 unit) and batch-check those ids with
 * videos.list (1 unit), so a normal resolution costs 2 units. search.list
 * stays as a safety net behind a cooldown, for the rare channel whose
 * running stream is missing from uploads.
 */
class YouTubeLive
{
    /** How long a resolved live id is trusted before re-checking. */
    private const TTL_HIT = 300;

    /** How long "nothing is live" sticks before we look again. */
    private const TTL_MISS = 300;

    /** Handle → channel id barely ever changes; keep it a month. */
    private const TTL_CHANNEL = 2592000;

    /** Minimum gap between 100-unit search.list fallbacks. */
    private const SEARCH_COOLDOWN = 900;

    private const API = 'https://www.googleapis.com/youtube/v3/';

    /** Video id straight from a URL, or null when it's channel-style. */
    public static function videoIdFromUrl(?string $url): ?string
    {
        if (! $url) {
            return null;
        }
        if (preg_match('~(?:youtube\.com/(?:watch\?v=|live/|embed/|shorts/)|youtu\.be/)([A-Za-z0-9_-]{11})~', $url, $m)) {
            return $m[1];
        }

        return null;
    }

    /**
     * Current live video id — direct parse first, then a cached lookup
     * for channel-style URLs. Null when nothing is resolvable.
     */
    public static function resolveVideoId(?string $streamUrl, ?string $channelId = null): ?string
    {
        $direct = self::videoIdFromUrl($streamUrl);
        if ($direct !== null) {
            return $direct;
        }

        if (! $streamUrl && ! $channelId) {
            return null;
        }

        $key = 'youtube_live_video_id:'.sha1(($streamUrl ?? '').'|'.($channelId ?? ''));
        $cached = Cache::get($key);
        if ($cached === 'none') {
            return null;
        }
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $resolved = self::lookup($streamUrl, $channelId);

        // Short TTLs both ways: a hit must not outlive the stream by long,
        // and a miss must not hide a stream that just started.
        Cache::put($key, $resolved ?? 'none', $resolved !== null ? self::TTL_HIT : self::TTL_MISS);

        return $resolved;
    }

    /** Live-frame thumbnail for a video id. */
    public static function thumbnailUrl(string $videoId): string
    {
        return "https://i.ytimg.com/vi/{$videoId}/hqdefault_live.jpg";
    }

    /** The Data API key: admin-editable setting first, then env. */
    public static function apiKey(): ?string
    {
        $key = SystemSetting::getValue('youtube_api_key', '')
            ?: (string) config('services.youtube.key', '');

        return $key !== '' ? $key : null;
    }

    /**
     * Cheapest-first resolution: uploads playlist (2 units) → search.list
     * behind a cooldown (100 units) → HTML scrape (keyless fallback).
     */
    private static function lookup(?string $streamUrl, ?string $channelId): ?string
    {
        if (self::apiKey() !== null) {
            $resolvedChannel = self::resolveChannelId($streamUrl, $channelId);

            if ($resolvedChannel !== null) {
                $viaUploads = self::liveFromUploads($resolvedChannel);
                if ($viaUploads !== null) {
                    return $viaUploads;
                }

                $viaSearch = self::liveFromSearch($resolvedChannel);
                if ($viaSearch !== null) {
                    return $viaSearch;
                }
            }
        }

        return self::liveFromHtml($streamUrl, $channelId);
    }

    /**
     * A usable UC… channel id. The stored setting is trusted only when it
     * looks like one — prod held a bare handle there for months, which
     * silently 404'd every /channel/{id}/live probe. Otherwise resolve the
     *
     * @handle out of the stream URL (1 unit, cached a month).
     */
    private static function resolveChannelId(?string $streamUrl, ?string $channelId): ?string
    {
        if ($channelId && preg_match('~^UC[A-Za-z0-9_-]{22}$~', $channelId)) {
            return $channelId;
        }

        if (! $streamUrl || ! preg_match('~youtube\.com/@([A-Za-z0-9._-]+)~', $streamUrl, $m)) {
            return null;
        }
        $handle = $m[1];

        $resolved = Cache::remember(
            'youtube_channel_id:'.strtolower($handle),
            self::TTL_CHANNEL,
            function () use ($handle): string {
                $data = self::api('channels', ['part' => 'id', 'forHandle' => '@'.$handle]);

                return $data['items'][0]['id'] ?? 'none';
            },
        );

        return $resolved === 'none' ? null : $resolved;
    }

    /**
     * Recent uploads carry the running stream for virtually every channel,
     * and the uploads playlist id is the channel id with a UU prefix — so
     * no extra call is needed to find it.
     */
    private static function liveFromUploads(string $channelId): ?string
    {
        $uploads = 'UU'.substr($channelId, 2);

        $playlist = self::api('playlistItems', [
            'part' => 'contentDetails',
            'playlistId' => $uploads,
            'maxResults' => 50,
        ]);

        $ids = array_values(array_filter(array_map(
            fn (array $item): ?string => $item['contentDetails']['videoId'] ?? null,
            $playlist['items'] ?? [],
        )));

        if ($ids === []) {
            return null;
        }

        // One batched videos.list for all 50 — still a single unit.
        $videos = self::api('videos', [
            'part' => 'snippet',
            'id' => implode(',', $ids),
        ]);

        foreach ($videos['items'] ?? [] as $video) {
            if (($video['snippet']['liveBroadcastContent'] ?? '') === 'live') {
                return $video['id'] ?? null;
            }
        }

        return null;
    }

    /**
     * The 100-unit fallback. Rate-limited so a long darshan window with no
     * stream running can't drain the daily quota one poll at a time.
     */
    private static function liveFromSearch(string $channelId): ?string
    {
        $cooldown = 'youtube_live_search_cooldown:'.$channelId;
        if (Cache::get($cooldown) !== null) {
            return null;
        }
        Cache::put($cooldown, 1, self::SEARCH_COOLDOWN);

        $data = self::api('search', [
            'part' => 'id',
            'channelId' => $channelId,
            'eventType' => 'live',
            'type' => 'video',
            'maxResults' => 1,
        ]);

        return $data['items'][0]['id']['videoId'] ?? null;
    }

    /**
     * Keyless fallback: the /live page canonicalises to the current live
     * watch URL. Works locally; blocked from the production VPS (see the
     * class docblock), which is exactly why the Data API path exists.
     */
    private static function liveFromHtml(?string $streamUrl, ?string $channelId): ?string
    {
        $probes = array_filter([
            $streamUrl,
            $channelId ? "https://www.youtube.com/channel/{$channelId}/live" : null,
        ]);

        foreach ($probes as $probe) {
            try {
                $html = Http::timeout(4)
                    ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; TemplePortal)'])
                    ->get($probe)
                    ->body();

                if (preg_match('~<link rel="canonical" href="https://www\.youtube\.com/watch\?v=([A-Za-z0-9_-]{11})"~', $html, $m)) {
                    return $m[1];
                }
            } catch (\Throwable) {
                // Network hiccup — fall through to the next probe.
            }
        }

        return null;
    }

    /** One Data API call. Returns [] on any failure — never throws. */
    private static function api(string $endpoint, array $query): array
    {
        $key = self::apiKey();
        if ($key === null) {
            return [];
        }

        try {
            $response = Http::timeout(6)
                ->get(self::API.$endpoint, $query + ['key' => $key]);

            if ($response->failed()) {
                // Quota exhaustion and a bad/over-restricted key both land
                // here; the caller's cache keeps this from repeating hard.
                Log::warning('YouTube Data API call failed', [
                    'endpoint' => $endpoint,
                    'status' => $response->status(),
                    'reason' => $response->json('error.errors.0.reason'),
                ]);

                return [];
            }

            return (array) $response->json();
        } catch (\Throwable $e) {
            Log::warning('YouTube Data API unreachable', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }
}
