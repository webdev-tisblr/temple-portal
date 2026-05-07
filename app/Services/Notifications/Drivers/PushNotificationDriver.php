<?php

declare(strict_types=1);

namespace App\Services\Notifications\Drivers;

use App\Models\NotificationTemplate;
use App\Services\FirebaseService;
use App\Services\Notifications\Contracts\NotificationDriver;
use App\Services\Notifications\NotificationContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Push channel — fans out a rendered title/body to every registered
 * FCM token belonging to the resolved devotee.
 *
 * The actual FCM HTTP V1 wire-up arrives in Phase 5 (kreait/firebase-php
 * + service-account credentials). For now this driver:
 *   • builds the per-locale title/body
 *   • looks up the devotee's device tokens
 *   • dispatches a queued SendPushNotification job per token
 * The job no-ops gracefully if FCM credentials aren't yet configured,
 * so this driver is safe to wire from Phase 1 onward.
 */
final class PushNotificationDriver implements NotificationDriver
{
    public function __construct(private readonly FirebaseService $firebase)
    {
    }

    public function channel(): string
    {
        return NotificationTemplate::CHANNEL_PUSH;
    }

    public function send(NotificationTemplate $template, NotificationContext $context): bool
    {
        $devotee = $context->get('devotee');
        if (! $devotee instanceof Model) {
            Log::warning('Notification: push needs a devotee in context', [
                'template_key' => $template->key,
            ]);
            return false;
        }

        $locale = $context->get('locale', 'gu');
        $titles = $template->push_title ?? [];
        $bodies = $template->push_body ?? [];

        $title = $context->render(
            (string) ($titles[$locale] ?? $titles['gu'] ?? $titles['en'] ?? ''),
            $template->placeholder_map ?? []
        );
        $body = $context->render(
            (string) ($bodies[$locale] ?? $bodies['gu'] ?? $bodies['en'] ?? ''),
            $template->placeholder_map ?? []
        );

        if (trim($title) === '' && trim($body) === '') {
            Log::warning('Notification: push title+body empty after render', [
                'template_key' => $template->key,
            ]);
            return false;
        }

        $tokens = DB::table('temple_device_tokens')
            ->where('devotee_id', $devotee->getKey())
            ->where('is_active', true)
            ->pluck('token')
            ->all();

        if (empty($tokens)) {
            Log::info('Notification: push skipped (no active device tokens)', [
                'template_key' => $template->key,
                'devotee_id' => $devotee->getKey(),
            ]);
            return false;
        }

        $deepLink = $template->push_deep_link
            ? $context->render($template->push_deep_link, $template->placeholder_map ?? [])
            : null;

        $payload = array_filter([
            'deep_link' => $deepLink,
            'notification_key' => $template->key,
        ]);

        // FirebaseService::sendToMultiple is a no-op when FCM credentials
        // aren't configured (Phase 5 wires up service-account auth), so
        // this driver is safe to call from Phase 1.
        try {
            $this->firebase->sendToMultiple($tokens, $title, $body, $payload);
            return true;
        } catch (\Throwable $e) {
            Log::error('Notification: push send failed', [
                'template_key' => $template->key,
                'devotee_id' => $devotee->getKey(),
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
