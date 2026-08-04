<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Models\Devotee;
use App\Models\Notification;
use App\Services\FirebaseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SendPushNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public Notification $notification,
    ) {}

    public function handle(FirebaseService $firebaseService): void
    {
        $notification = $this->notification->fresh() ?? $this->notification;

        // Idempotency: don't re-send something that's already sent.
        if (in_array($notification->status, ['sent', 'failed'], true)) {
            return;
        }

        $notification->update(['status' => 'sending']);

        try {
            $tokens = $this->resolveTokens($notification);

            if (empty($tokens)) {
                Log::info('SendPushNotification: no device tokens found', [
                    'notification_id' => $notification->id,
                    'segment' => $notification->segment,
                ]);
                $notification->update([
                    'status' => 'sent',
                    'sent_at' => now(),
                    'total_recipients' => 0,
                    'delivered_count' => 0,
                ]);
                return;
            }

            $totalRecipients = count($tokens);

            // One FCM multicast per language group, so each recipient gets
            // the push in their own language (title_{lang} ?: gu ?: en).
            // Tokens without a devotee (or an unknown language) go to 'gu'.
            $groups = $this->groupTokensByLanguage($tokens);
            $groupCounts = array_map('count', $groups);

            // FCM `data` payload values must all be strings — the Flutter
            // side json-decodes intent_params when navigating.
            $data = array_filter([
                'notification_id' => (string) $notification->id,
                'intent' => $notification->intent,
                'intent_params' => $notification->intent_params
                    ? json_encode($notification->intent_params, JSON_UNESCAPED_UNICODE)
                    : null,
            ], fn ($v) => $v !== null && $v !== '');

            // image_url column stores the relative R2 path (e.g. 'notifications/xyz.jpg').
            // FCM needs a full https URL, so resolve via the image_url() helper
            // which is idempotent (passes through if already absolute).
            $imageUrl = $notification->image_url ? image_url($notification->image_url) : null;

            Log::info('SendPushNotification: dispatching to FCM', [
                'notification_id' => $notification->id,
                'intent' => $notification->intent,
                'intent_params' => $notification->intent_params,
                'data_payload' => $data,
                'token_count' => $totalRecipients,
                'by_language' => $groupCounts,
            ]);

            $successTotal = 0;
            $failureTotal = 0;
            $invalidTokens = [];

            foreach ($groups as $lang => $groupTokens) {
                $title = $notification->{"title_{$lang}"}
                    ?: ($notification->title_gu ?: ($notification->title_en ?: 'શ્રી પાતાળિયા હનુમાનજી'));
                $body = $notification->{"body_{$lang}"}
                    ?: ($notification->body_gu ?: ($notification->body_en ?: ''));

                $results = $firebaseService->sendToMultiple(
                    $groupTokens,
                    $title,
                    $body,
                    $data,
                    $imageUrl,
                );

                $successTotal += (int) ($results['success'] ?? 0);
                $failureTotal += (int) ($results['failure'] ?? 0);
                if (!empty($results['invalid_tokens'])) {
                    $invalidTokens = array_merge($invalidTokens, $results['invalid_tokens']);
                }
            }

            if (!empty($invalidTokens)) {
                DeviceToken::whereIn('token', $invalidTokens)
                    ->update(['is_active' => false]);

                Log::info('SendPushNotification: deactivated invalid tokens', [
                    'count' => count($invalidTokens),
                ]);
            }

            $notification->update([
                'status' => 'sent',
                'sent_at' => now(),
                'total_recipients' => $totalRecipients,
                'delivered_count' => $successTotal,
            ]);

            Log::info('SendPushNotification: completed', [
                'notification_id' => $notification->id,
                'total' => $totalRecipients,
                'success' => $successTotal,
                'failure' => $failureTotal,
                'by_language' => $groupCounts,
            ]);

        } catch (\Throwable $e) {
            Log::error('SendPushNotification: job failed', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
            ]);

            $notification->update(['status' => 'failed']);

            throw $e;
        }
    }

    /**
     * Group token values by the owning devotee's language.
     *
     * Tokens without a devotee, or whose devotee has no recognised
     * language, land in the 'gu' group (temple default).
     *
     * @param array<int, array{token: string, devotee_id: string|null}> $tokens
     * @return array<string, array<int, string>> language => token values
     */
    private function groupTokensByLanguage(array $tokens): array
    {
        $devoteeIds = array_values(array_unique(array_filter(array_column($tokens, 'devotee_id'))));

        $languages = empty($devoteeIds)
            ? collect()
            : Devotee::whereIn('id', $devoteeIds)->pluck('language', 'id');

        $groups = [];
        foreach ($tokens as $row) {
            $lang = $row['devotee_id'] !== null ? ($languages[$row['devotee_id']] ?? null) : null;
            $lang = $lang instanceof \BackedEnum ? $lang->value : $lang;
            if (!in_array($lang, ['gu', 'hi', 'en'], true)) {
                $lang = 'gu';
            }
            $groups[$lang][] = $row['token'];
        }

        return $groups;
    }

    /**
     * Resolve FCM device tokens based on notification segment.
     *
     * @return array<int, array{token: string, devotee_id: string|null}>
     */
    private function resolveTokens(Notification $notification): array
    {
        $segment = $notification->segment ?? 'all';
        $query = DeviceToken::query()
            ->where('is_active', true)
            ->select('token', 'devotee_id');

        return match ($segment) {
            'donors' => $query
                ->whereHas('devotee', function ($q) {
                    $q->whereExists(function ($sub) {
                        $sub->select(DB::raw(1))
                            ->from('temple_donations')
                            ->whereColumn('temple_donations.devotee_id', 'temple_devotees.id');
                    });
                })
                ->get()
                ->toArray(),

            'active_users' => $query
                ->whereHas('devotee', function ($q) {
                    $q->where('last_login_at', '>=', now()->subDays(30));
                })
                ->get()
                ->toArray(),

            'inactive_users' => $query
                ->whereHas('devotee', function ($q) {
                    $q->where(function ($sub) {
                        $sub->whereNull('last_login_at')
                            ->orWhere('last_login_at', '<', now()->subDays(30));
                    });
                })
                ->get()
                ->toArray(),

            'birthday_today' => $query
                ->whereHas('devotee', function ($q) {
                    $q->whereMonth('date_of_birth', now()->month)
                        ->whereDay('date_of_birth', now()->day);
                })
                ->get()
                ->toArray(),

            'custom' => $this->resolveCustomTokens($notification, $query),

            default => $query->get()->toArray(), // 'all'
        };
    }

    /**
     * @return array<int, array{token: string, devotee_id: string|null}>
     */
    private function resolveCustomTokens(Notification $notification, \Illuminate\Database\Eloquent\Builder $query): array
    {
        $filter = $notification->custom_filter ?? [];

        if (!empty($filter['devotee_ids'])) {
            $query->whereIn('devotee_id', $filter['devotee_ids']);
        }

        if (!empty($filter['city'])) {
            $query->whereHas('devotee', function ($q) use ($filter) {
                $q->where('city', $filter['city']);
            });
        }

        if (!empty($filter['language'])) {
            $query->whereHas('devotee', function ($q) use ($filter) {
                $q->where('language', $filter['language']);
            });
        }

        return $query->get()->toArray();
    }
}
