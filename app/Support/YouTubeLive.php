<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Resolve the CURRENT live video id for the temple's YouTube stream.
 *
 * Admins paste either a direct video URL (watch?v=… / youtu.be/… /
 * youtube.com/live/…) — trivially parseable — or a channel-style URL
 * (/@handle/live, /channel/…/live) whose current video id changes every
 * stream. For the latter we fetch the page and read the canonical watch
 * URL YouTube embeds in it. Cached briefly so page/API traffic doesn't
 * hammer YouTube; failures cache as "none" to avoid repeated 4s stalls.
 */
class YouTubeLive
{
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
     * of the channel's live page. Null when nothing is resolvable.
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

        $resolved = Cache::remember(
            'youtube_live_video_id:'.sha1(($streamUrl ?? '').'|'.($channelId ?? '')),
            120,
            function () use ($streamUrl, $channelId): string {
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

                        // The /live page canonicalises to the current live
                        // watch URL while a stream is running.
                        if (preg_match('~<link rel="canonical" href="https://www\.youtube\.com/watch\?v=([A-Za-z0-9_-]{11})"~', $html, $m)) {
                            return $m[1];
                        }
                    } catch (\Throwable) {
                        // Network hiccup — fall through to the next probe.
                    }
                }

                return 'none';
            },
        );

        return $resolved === 'none' ? null : $resolved;
    }

    /** Live-frame thumbnail for a video id. */
    public static function thumbnailUrl(string $videoId): string
    {
        return "https://i.ytimg.com/vi/{$videoId}/hqdefault_live.jpg";
    }
}
