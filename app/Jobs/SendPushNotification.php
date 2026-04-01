<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\DeviceToken;
use App\Models\Donation;
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
        $notification = $this->notification;

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

            $tokenValues = array_column($tokens, 'token');
            $totalRecipients = count($tokenValues);

            $title = $notification->title_gu ?? $notification->title_en ?? 'શ્રી પાતળિયા હનુમાનજી';
            $body = $notification->body_gu ?? $notification->body_en ?? '';

            $data = array_filter([
                'notification_id' => (string) $notification->id,
                'image_url' => $notification->image_url ?? null,
            ]);

            $results = $firebaseService->sendToMultiple($tokenValues, $title, $body, $data);

            // Mark invalid tokens as inactive
            if (!empty($results['invalid_tokens'])) {
                DeviceToken::whereIn('token', $results['invalid_tokens'])
                    ->update(['is_active' => false]);

                Log::info('SendPushNotification: marked invalid tokens as inactive', [
                    'count' => count($results['invalid_tokens']),
                ]);
            }

            $notification->update([
                'status' => 'sent',
                'sent_at' => now(),
                'total_recipients' => $totalRecipients,
                'delivered_count' => $results['success'],
            ]);

            Log::info('SendPushNotification: completed', [
                'notification_id' => $notification->id,
                'total' => $totalRecipients,
                'success' => $results['success'],
                'failure' => $results['failure'],
            ]);

        } catch (\Exception $e) {
            Log::error('SendPushNotification: job failed', [
                'notification_id' => $notification->id,
                'error' => $e->getMessage(),
            ]);

            $notification->update(['status' => 'failed']);

            throw $e;
        }
    }

    /**
     * Resolve FCM device tokens based on notification segment.
     *
     * @return array<int, array{token: string, devotee_id: mixed}>
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
     * Resolve tokens for custom-filtered segments.
     *
     * @return array<int, array{token: string, devotee_id: mixed}>
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
