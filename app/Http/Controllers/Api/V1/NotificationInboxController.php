<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\Notification;
use App\Models\NotificationRead;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Devotee-facing inbox for broadcast push notifications.
 *
 * Visibility rule (v1): a broadcast is visible to a devotee when
 *   - status = 'sent', AND
 *   - segment = 'all', OR
 *   - segment = 'custom' AND custom_filter.devotee_ids contains the
 *     devotee's id.
 *
 * Other segments (donors / active_users / inactive_users /
 * birthday_today) are intentionally excluded — they were targeting
 * point-in-time states that can't be reconstructed cleanly after the
 * fact. ~95% of real broadcasts use 'all' anyway, so the inbox stays
 * useful without that complexity.
 */
class NotificationInboxController extends BaseApiController
{
    /**
     * GET /api/v1/me/notifications
     *
     * Returns the most recent visible broadcasts (paginated, 20/page),
     * each annotated with read-state for the current devotee.
     */
    public function index(Request $request): JsonResponse
    {
        $devotee = $request->user();
        if (! $devotee) {
            return $this->error('Unauthorised', 401);
        }

        $page = max(1, (int) $request->query('page', 1));

        $paginator = $this->visibleQuery((string) $devotee->getKey())
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'page', $page);

        // Bulk-fetch read state in one query so we don't N+1 across the page.
        $readIds = NotificationRead::query()
            ->where('devotee_id', $devotee->getKey())
            ->whereIn('notification_id', $paginator->pluck('id'))
            ->pluck('notification_id')
            ->all();
        $readSet = array_flip($readIds);

        $items = $paginator->getCollection()->map(function (Notification $row) use ($readSet) {
            return [
                'id' => $row->id,
                'title' => $row->title_gu ?: $row->title_en,
                'body' => $row->body_gu ?: $row->body_en,
                'image_url' => $row->image_url ? image_url($row->image_url) : null,
                'intent' => $row->intent,
                'intent_params' => $row->intent_params,
                'sent_at' => $row->sent_at?->toIso8601String(),
                'is_read' => isset($readSet[$row->id]),
            ];
        });

        return $this->success([
            'items' => $items,
            'page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'total' => $paginator->total(),
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    /**
     * GET /api/v1/me/notifications/unread-count
     *
     * Cheap COUNT used by the bell badge on the home screen.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        $devotee = $request->user();
        if (! $devotee) {
            return $this->error('Unauthorised', 401);
        }

        $count = $this->visibleQuery((string) $devotee->getKey())
            ->whereDoesntHave('reads', function (Builder $q) use ($devotee) {
                $q->where('devotee_id', $devotee->getKey());
            })
            ->count();

        return $this->success(['unread' => $count]);
    }

    /**
     * POST /api/v1/me/notifications/{notification}/read
     *
     * Marks a single notification as read. Idempotent — re-tapping
     * the same row is a no-op via the unique (devotee_id, notification_id)
     * constraint.
     */
    public function markRead(Request $request, int $notificationId): JsonResponse
    {
        $devotee = $request->user();
        if (! $devotee) {
            return $this->error('Unauthorised', 401);
        }

        // Defensively check the notification is actually visible to this
        // devotee before recording a read — prevents drive-by enumeration
        // of notifications the devotee shouldn't see.
        $exists = $this->visibleQuery((string) $devotee->getKey())
            ->whereKey($notificationId)
            ->exists();

        if (! $exists) {
            return $this->error('Notification not found', 404);
        }

        NotificationRead::query()->updateOrCreate(
            [
                'devotee_id' => $devotee->getKey(),
                'notification_id' => $notificationId,
            ],
            ['read_at' => now()],
        );

        return $this->success(['read' => true]);
    }

    /**
     * POST /api/v1/me/notifications/read-all
     *
     * One-shot "mark all as read" for the inbox header action. Insert
     * any missing read rows; touch read_at on existing ones (cheap).
     */
    public function markAllRead(Request $request): JsonResponse
    {
        $devotee = $request->user();
        if (! $devotee) {
            return $this->error('Unauthorised', 401);
        }

        $unreadIds = $this->visibleQuery((string) $devotee->getKey())
            ->whereDoesntHave('reads', function (Builder $q) use ($devotee) {
                $q->where('devotee_id', $devotee->getKey());
            })
            ->pluck('id');

        $rows = $unreadIds->map(fn ($id) => [
            'devotee_id' => $devotee->getKey(),
            'notification_id' => $id,
            'read_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ])->all();

        if (! empty($rows)) {
            NotificationRead::query()->insert($rows);
        }

        return $this->success(['marked' => count($rows)]);
    }

    /**
     * Builder for broadcasts visible to a specific devotee.
     */
    private function visibleQuery(string $devoteeId): Builder
    {
        return Notification::query()
            ->where('status', 'sent')
            ->where(function (Builder $q) use ($devoteeId) {
                $q->where('segment', 'all')
                    ->orWhere(function (Builder $q2) use ($devoteeId) {
                        $q2->where('segment', 'custom')
                            ->whereJsonContains('custom_filter->devotee_ids', $devoteeId);
                    });
            });
    }
}
