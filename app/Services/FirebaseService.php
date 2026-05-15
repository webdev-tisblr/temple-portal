<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Kreait\Firebase\Exception\Messaging\NotFound;
use Kreait\Firebase\Messaging;
use Kreait\Firebase\Messaging\AndroidConfig;
use Kreait\Firebase\Messaging\ApnsConfig;
use Kreait\Firebase\Messaging\CloudMessage;
use Kreait\Firebase\Messaging\Notification as FcmNotification;
use Kreait\Firebase\Messaging\WebPushConfig;
use Throwable;

/**
 * Thin wrapper around kreait's Firebase Messaging for our two send paths:
 * trigger-based templates (PushNotificationDriver) and admin broadcasts
 * (SendPushNotification job). Both go through sendToMultiple().
 *
 * Sending fails closed: when credentials are missing or FCM is unreachable
 * the service logs and returns failure counts — it never throws. This
 * keeps a misconfigured FCM project from breaking the calling code path
 * (e.g. a payment-capture transaction).
 */
class FirebaseService
{
    /**
     * @param  array<string, string>  $data  FCM data payload — values MUST be strings
     */
    public function sendToDevice(string $token, string $title, string $body, array $data = [], ?string $imageUrl = null): bool
    {
        $messaging = $this->messaging();
        if (! $messaging) {
            return false;
        }

        try {
            $message = $this->buildMessage($title, $body, $data, $imageUrl)
                ->toToken($token);
            $messaging->send($message);
            return true;
        } catch (NotFound) {
            // Token is dead — caller should deactivate it.
            return false;
        } catch (Throwable $e) {
            Log::warning('FCM single-token send failed', [
                'error' => $e->getMessage(),
                'token_tail' => substr($token, -8),
            ]);
            return false;
        }
    }

    /**
     * Multi-cast send. Returns counts + the list of dead tokens that the
     * caller should mark inactive.
     *
     * @param  array<int, string>  $tokens
     * @param  array<string, string>  $data  FCM data payload — values MUST be strings
     * @return array{success: int, failure: int, invalid_tokens: array<int, string>}
     */
    public function sendToMultiple(array $tokens, string $title, string $body, array $data = [], ?string $imageUrl = null): array
    {
        $results = ['success' => 0, 'failure' => 0, 'invalid_tokens' => []];

        if (empty($tokens)) {
            return $results;
        }

        $messaging = $this->messaging();
        if (! $messaging) {
            $results['failure'] = count($tokens);
            return $results;
        }

        $message = $this->buildMessage($title, $body, $data, $imageUrl);

        // FCM sendMulticast supports up to 500 tokens per call.
        foreach (array_chunk($tokens, 500) as $chunk) {
            try {
                $report = $messaging->sendMulticast($message, $chunk);

                $results['success'] += $report->successes()->count();
                $results['failure'] += $report->failures()->count();

                foreach ($report->failures()->getItems() as $failure) {
                    $errorMessage = $failure->error()?->getMessage() ?? '';
                    $errorClass = $failure->error() ? get_class($failure->error()) : 'unknown';
                    $tokenValue = (string) $failure->target()->value();
                    $tokenTail = substr($tokenValue, -8);

                    Log::warning('FCM message rejected', [
                        'token_tail' => $tokenTail,
                        'error_class' => $errorClass,
                        'error_message' => $errorMessage,
                    ]);

                    // Detect dead tokens by EXCEPTION CLASS (most reliable
                    // signal — kreait normalises this) and a broader set of
                    // message strings as a fallback for older versions.
                    $isDead =
                        str_contains($errorClass, 'NotFound')
                        || str_contains($errorClass, 'InvalidArgument')
                        || str_contains($errorMessage, 'not-registered')
                        || str_contains($errorMessage, 'invalid-registration')
                        || str_contains($errorMessage, 'NOT_FOUND')
                        || str_contains($errorMessage, 'UNREGISTERED')
                        || str_contains($errorMessage, 'Requested entity was not found');

                    if ($isDead) {
                        $results['invalid_tokens'][] = $tokenValue;
                    }
                }
            } catch (Throwable $e) {
                $results['failure'] += count($chunk);
                Log::warning('FCM batch send failed', [
                    'error' => $e->getMessage(),
                    'error_class' => get_class($e),
                    'batch_size' => count($chunk),
                ]);
            }
        }

        return $results;
    }

    /**
     * Build a CloudMessage with optional image + platform-specific config.
     *
     * Android: high-priority delivery, uses our 'temple_default' channel.
     * APNs: priority 10, mutable-content=1 so a Notification Service Extension
     *       can attach the image. Without that extension the title/body still
     *       render — only the image is hidden on iOS.
     *
     * @param  array<string, string>  $data
     */
    private function buildMessage(string $title, string $body, array $data, ?string $imageUrl): CloudMessage
    {
        $notification = $imageUrl
            ? FcmNotification::create($title, $body, $imageUrl)
            : FcmNotification::create($title, $body);

        $message = CloudMessage::new()
            ->withNotification($notification)
            ->withAndroidConfig(AndroidConfig::fromArray([
                'priority' => 'high',
                'notification' => array_filter([
                    'channel_id' => 'temple_default',
                    'sound' => 'default',
                    'image' => $imageUrl,
                ], fn ($v) => $v !== null),
            ]))
            ->withApnsConfig(ApnsConfig::fromArray(array_filter([
                'headers' => [
                    'apns-priority' => '10',
                ],
                'payload' => [
                    'aps' => array_filter([
                        'sound' => 'default',
                        // mutable-content is only required for image-bearing
                        // notifications. Omitting the key entirely (vs setting
                        // it to 0) keeps the APNs payload smaller and avoids
                        // some FCM validators flagging an empty fcm_options.
                        'mutable-content' => $imageUrl ? 1 : null,
                    ], fn ($v) => $v !== null),
                ],
                // Only attach fcm_options when there's actually an image —
                // FCM rejects an empty fcm_options object on some paths.
                'fcm_options' => $imageUrl ? ['image' => $imageUrl] : null,
            ], fn ($v) => $v !== null)));

        // WebPushConfig only matters for browser push; only attach when we
        // actually have an icon to set.
        if ($imageUrl) {
            $message = $message->withWebPushConfig(WebPushConfig::fromArray([
                'notification' => ['icon' => $imageUrl],
            ]));
        }

        // Attach the data payload only if there's actually something to send.
        // kreait accepts withData([]) but some FCM intermediates have been
        // observed rejecting it.
        if (! empty($data)) {
            $message = $message->withData($data);
        }

        return $message;
    }

    /**
     * Resolve the messaging client. Returns null (with a logged warning) if
     * credentials are missing — the caller treats that as a soft failure.
     */
    private function messaging(): ?Messaging
    {
        try {
            return app('firebase.messaging');
        } catch (Throwable $e) {
            Log::warning('FCM not configured — set FIREBASE_CREDENTIALS to your service-account JSON path', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }
}
