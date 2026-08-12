<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Donation;
use App\Models\DonationBoardEntry;
use App\Models\SystemSetting;
use App\Support\CampaignDonors;
use App\Support\DevoteeLocale;
use Illuminate\Support\Facades\Log;

/**
 * The live donor display board — a screen in the temple hall that announces
 * each donation as it is captured (2026-08-13).
 *
 * Two rules govern everything in this class:
 *
 * 1. IT MAY NEVER BREAK A PAYMENT. announce() is called from
 *    PaymentCaptureService's post-commit block and is wrapped in try/catch
 *    there, but it also swallows its own errors — a display board is
 *    decoration, and a donor's money must not depend on it.
 *
 * 2. THE PAYLOAD IS AN ALLOWLIST, NOT A FILTER. Only the six keys built in
 *    snapshot() ever reach the screen. `purpose` in particular is 500 chars of
 *    unreviewed donor free text and is deliberately absent — there is no
 *    toggle for it, because a toggle is just a slower path to putting it on a
 *    ten-foot screen in front of the congregation. Same for notes/extra_data,
 *    phone, email, PAN and receipt numbers.
 */
class DisplayBoardService
{
    /** Feed page size, and the hard ceiling a client can ask for. */
    private const DEFAULT_LIMIT = 10;
    private const MAX_LIMIT = 50;

    /** How far back a takedown stays advertised, so on-air entries get pulled. */
    private const SUPPRESSED_WINDOW_MINUTES = 30;

    /** Size of the attract-loop honour roll returned on every poll. */
    private const RECENT_LIMIT = 12;

    /**
     * Record a captured donation for the board.
     *
     * Idempotent: `donation_id` is UNIQUE, so a replayed capture (webhook plus
     * client verify, both legitimate) announces once. Returns null when the
     * board is off or the gift was already announced.
     */
    public function announce(Donation $donation): ?DonationBoardEntry
    {
        try {
            if (! $this->enabled()) {
                return null;
            }

            if (DonationBoardEntry::where('donation_id', $donation->id)->exists()) {
                return null;
            }

            $now = now();

            return DonationBoardEntry::create([
                'donation_id' => $donation->id,
                'payload' => $this->snapshot($donation),
                // Wall clock, never paid_at — see the migration comment.
                'announced_at' => $now,
                'visible_from' => $now->copy()->addSeconds($this->delaySeconds()),
                'anonymous' => (bool) $donation->anonymous,
            ]);
        } catch (\Throwable $e) {
            // Belt to PaymentCaptureService's braces. A board failure is a
            // logged non-event, never an exception on the money path.
            Log::error('DisplayBoard: announce failed', [
                'donation_id' => $donation->id ?? null,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The frozen, public-safe snapshot.
     *
     * Masking is delegated to CampaignDonors::payload() — the file documented
     * as "The ONLY publicly safe shape for a donor row", whose own docblock
     * warns that a second copy of this logic is exactly how a donor ends up
     * named in one list and masked in another. It resolves the Gupt Daan mask
     * (રામ ભરોસે) and blanks the city.
     *
     * Rendered under the BOARD's configured locale, not the request locale: a
     * capture arriving by Razorpay webhook has no user locale at all, and the
     * hall screen must not change language because a staff member switched
     * theirs on their phone.
     *
     * @return array<string, mixed>
     */
    private function snapshot(Donation $donation): array
    {
        $donation->loadMissing('devotee', 'campaign', 'donationType');

        return DevoteeLocale::withLocale($this->locale(), function () use ($donation): array {
            $masked = CampaignDonors::payload(collect([$donation]))[0] ?? [];

            return [
                'name' => $masked['name'] ?? '',
                'city' => $masked['city'] ?? '',
                'amount' => (float) ($masked['amount'] ?? 0),
                'anonymous' => (bool) $donation->anonymous,
                // Admin-authored, locale-resolved accessors — safe to show.
                'campaign_title' => $donation->campaign?->title,
                'donation_type' => $donation->donationType?->name,
            ];
        });
    }

    /**
     * Everything the screen needs for one poll.
     *
     * `since` semantics:
     *   • absent  → COLD START. Returns no `entries` at all, only `latest_seq`
     *     and the honour roll. Without this every browser refresh would replay
     *     the whole day as a queue of takeovers.
     *   • present → strictly `id > since`, ascending, capped. Combined with the
     *     visibility lag this can neither replay nor skip.
     *
     * @return array<string, mixed>
     */
    public function feed(?int $since, int $limit = self::DEFAULT_LIMIT): array
    {
        $limit = max(1, min($limit, self::MAX_LIMIT));
        $enabled = $this->enabled();

        $latestSeq = (int) (DonationBoardEntry::showable()->max('id') ?? 0);

        $entries = [];
        if ($enabled && $since !== null) {
            $entries = DonationBoardEntry::showable()
                ->where('id', '>', $since)
                // Gupt Daan reaches the honour roll but never a takeover.
                ->when(
                    ! $this->announceAnonymous(),
                    fn ($q) => $q->where('anonymous', false),
                )
                ->orderBy('id')
                ->limit($limit)
                ->get()
                ->map(fn (DonationBoardEntry $e): array => $this->row($e))
                ->all();
        }

        return [
            'enabled' => $enabled,
            'latest_seq' => $latestSeq,
            'entries' => $entries,
            // Always sent, so the attract loop can run entirely from client
            // memory during a network outage.
            'recent' => $enabled ? $this->honourRoll() : [],
            // Lets the screen pull an entry that is already on air.
            'suppressed_ids' => $enabled ? $this->recentlySuppressedIds() : [],
            'server_time' => now()->toIso8601String(),
            'config' => [
                'announce_seconds' => $this->announceSeconds(),
                'show_amounts' => $this->showAmounts(),
                'show_city' => $this->showCity(),
                'locale' => $this->locale(),
                'headline' => $this->headline(),
            ],
        ];
    }

    /**
     * The attract-loop roll. Anonymous gifts ARE included (masked) and the
     * order is deliberately shuffled with no timestamps, so a Gupt Daan row
     * cannot be correlated with whoever was last at the counter.
     *
     * @return array<int, array<string, mixed>>
     */
    private function honourRoll(): array
    {
        return DonationBoardEntry::showable()
            ->orderByDesc('id')
            ->limit(self::RECENT_LIMIT)
            ->get()
            ->map(fn (DonationBoardEntry $e): array => $this->row($e, withSeq: false))
            ->shuffle()
            ->values()
            ->all();
    }

    /** @return array<int, int> */
    private function recentlySuppressedIds(): array
    {
        return DonationBoardEntry::whereNotNull('suppressed_at')
            ->where('suppressed_at', '>=', now()->subMinutes(self::SUPPRESSED_WINDOW_MINUTES))
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Wire shape. Reads ONLY the stored snapshot — never the donation relation
     * — so nothing outside the allowlist can leak in later.
     *
     * @return array<string, mixed>
     */
    private function row(DonationBoardEntry $entry, bool $withSeq = true): array
    {
        $p = $entry->payload ?? [];

        $row = [
            'name' => $p['name'] ?? '',
            'city' => $this->showCity() ? ($p['city'] ?? '') : '',
            'amount' => $this->showAmounts() ? (float) ($p['amount'] ?? 0) : null,
            'anonymous' => (bool) ($p['anonymous'] ?? false),
            'campaign_title' => $p['campaign_title'] ?? null,
            'donation_type' => $p['donation_type'] ?? null,
        ];

        if ($withSeq) {
            $row['seq'] = (int) $entry->id;
        }

        return $row;
    }

    // ── Settings ──────────────────────────────────────────────────────────
    // SystemSetting::getValue() is a rememberForever map invalidated on save,
    // so these are free to call per poll and the kill switch lands within one
    // poll cycle without any cache of our own.

    public function enabled(): bool
    {
        return SystemSetting::getValue('board_enabled', '0') === '1';
    }

    public function announceAnonymous(): bool
    {
        return SystemSetting::getValue('board_announce_anonymous', '0') === '1';
    }

    private function showAmounts(): bool
    {
        return SystemSetting::getValue('board_show_amounts', '1') === '1';
    }

    private function showCity(): bool
    {
        return SystemSetting::getValue('board_show_city', '1') === '1';
    }

    public function delaySeconds(): int
    {
        return max(0, (int) SystemSetting::getValue('board_delay_seconds', '5'));
    }

    private function announceSeconds(): int
    {
        return max(2, (int) SystemSetting::getValue('board_announce_seconds', '8'));
    }

    public function locale(): string
    {
        $locale = SystemSetting::getValue('board_locale', 'gu');

        return in_array($locale, DevoteeLocale::SUPPORTED, true) ? $locale : 'gu';
    }

    private function headline(): string
    {
        return SystemSetting::getValue('board_headline_'.$this->locale(), '');
    }

    /**
     * Shared secret for the kiosk URL and the feed.
     *
     * Minted on first read, mirroring SmsService::webhookToken(). It is a speed
     * bump against scraping a cross-campaign donor list, not real access
     * control — anyone can read the URL off the screen's address bar. The
     * payload allowlist is what actually protects donors.
     */
    public function accessToken(): string
    {
        $token = SystemSetting::getValue('board_access_token', '');

        if ($token === '') {
            $token = \Illuminate\Support\Str::random(40);
            SystemSetting::setValue('board_access_token', $token);
        }

        return $token;
    }

    public function regenerateAccessToken(): string
    {
        $token = \Illuminate\Support\Str::random(40);
        SystemSetting::setValue('board_access_token', $token);

        return $token;
    }

    /** Constant-time compare; callers 404 rather than 401 on a miss. */
    public function tokenMatches(?string $candidate): bool
    {
        if (! is_string($candidate) || $candidate === '') {
            return false;
        }

        return hash_equals($this->accessToken(), $candidate);
    }
}
