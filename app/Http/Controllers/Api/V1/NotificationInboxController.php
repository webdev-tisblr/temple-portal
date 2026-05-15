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
     * DELETE /api/v1/me/notifications/{notification}
     *
     * Dismiss a single notification from the devotee's inbox. The
     * underlying broadcast row stays — only this devotee's view of it
     * is hidden. Idempotent: dismissing an already-dismissed row is a
     * no-op via updateOrCreate.
     */
    public function dismiss(Request $request, int $notificationId): JsonResponse
    {
        $devotee = $request->user();
        if (! $devotee) {
            return $this->error('Unauthorised', 401);
        }

        // Defensive visibility check before recording state.
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
            [
                'dismissed_at' => now(),
                // Auto-mark as read too — a dismissed row is implicitly read.
                'read_at' => now(),
            ],
        );

        return $this->success(['dismissed' => true]);
    }

    /**
     * DELETE /api/v1/me/notifications
     *
     * Dismiss every currently-visible notification in one call.
     * Powers the inbox "Clear all" header action.
     */
    public function dismissAll(Request $request): JsonResponse
    {
        $devotee = $request->user();
        if (! $devotee) {
            return $this->error('Unauthorised', 401);
        }

        $visibleIds = $this->visibleQuery((string) $devotee->getKey())->pluck('id');

        if ($visibleIds->isEmpty()) {
            return $this->success(['dismissed' => 0]);
        }

        // Upsert in one pass: existing rows get dismissed_at touched,
        // missing rows get created. Using a loop because Eloquent's
        // upsert helper doesn't play well with the unique compound key
        // across all drivers.
        $now = now();
        foreach ($visibleIds as $id) {
            NotificationRead::query()->updateOrCreate(
                [
                    'devotee_id' => $devotee->getKey(),
                    'notification_id' => $id,
                ],
                [
                    'dismissed_at' => $now,
                    'read_at' => $now,
                ],
            );
        }

        return $this->success(['dismissed' => $visibleIds->count()]);
    }

    /**
     * Builder for broadcasts visible to a specific devotee. Excludes
     * rows the devotee has already dismissed.
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
            })
            ->whereDoesntHave('reads', function (Builder $q) use ($devoteeId) {
                $q->where('devotee_id', $devoteeId)
                    ->whereNotNull('dismissed_at');
            });
    }
}
