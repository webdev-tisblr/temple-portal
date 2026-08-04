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
     * Minimum app version whose builds bundle the custom tone sound files
     * and Android notification channels. Older builds must NEVER receive a
     * custom-tone payload: Android silently drops a push aimed at a channel
     * that doesn't exist on the device.
     */
    private const CUSTOM_TONE_MIN_VERSION = '1.4.6';

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
            $capable = $this->customToneConfigured() !== null
                && $this->isToneCapable(
                    \App\Models\DeviceToken::query()->where('token', $token)->value('app_version'),
                );
            $message = $this->buildMessage($title, $body, $data, $imageUrl, $capable)
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

        // When a custom tone is configured, split the fleet into tone-capable
        // tokens (registered by a build ≥ CUSTOM_TONE_MIN_VERSION) and legacy
        // tokens, and send each cohort a payload it can actually render —
        // legacy devices get the default channel instead of a silent drop.
        $groups = [];
        if ($this->customToneConfigured() === null) {
            $groups[] = [false, $tokens];
        } else {
            [$capable, $legacy] = $this->partitionByToneCapability($tokens);
            if ($capable !== []) {
                $groups[] = [true, $capable];
            }
            if ($legacy !== []) {
                $groups[] = [false, $legacy];
            }
        }

        foreach ($groups as [$toneCapable, $groupTokens]) {
            $message = $this->buildMessage($title, $body, $data, $imageUrl, $toneCapable);

            // FCM sendMulticast supports up to 500 tokens per call.
            foreach (array_chunk($groupTokens, 500) as $chunk) {
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
        }

        return $results;
    }

    /**
     * The admin-selected custom tone key, or null when the default tone is
     * active. Valid keys = tones actually bundled in app builds
     * ≥ CUSTOM_TONE_MIN_VERSION ('ghanti'/'aarti' rejoin when their clips
     * ship). An unknown value behaves as default, never a broken channel.
     */
    private function customToneConfigured(): ?string
    {
        $tone = \App\Models\SystemSetting::getValue('push_notification_tone', 'default');

        return in_array($tone, ['jayshreeram'], true) ? $tone : null;
    }

    /**
     * A device can render the custom tone only if its registration reported
     * an app version ≥ CUSTOM_TONE_MIN_VERSION. Null (builds ≤ 1.4.5 never
     * report one) = not capable.
     */
    private function isToneCapable(?string $appVersion): bool
    {
        return $appVersion !== null
            && version_compare($appVersion, self::CUSTOM_TONE_MIN_VERSION, '>=');
    }

    /**
     * @param  array<int, string>  $tokens
     * @return array{0: array<int, string>, 1: array<int, string>}  [capable, legacy]
     */
    private function partitionByToneCapability(array $tokens): array
    {
        $versions = \App\Models\DeviceToken::query()
            ->whereIn('token', $tokens)
            ->pluck('app_version', 'token');

        $capable = [];
        $legacy = [];
        foreach ($tokens as $token) {
            if ($this->isToneCapable($versions[$token] ?? null)) {
                $capable[] = $token;
            } else {
                $legacy[] = $token;
            }
        }

        return [$capable, $legacy];
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
    private function buildMessage(string $title, string $body, array $data, ?string $imageUrl, bool $customToneCapable = false): CloudMessage
    {
        $notification = $imageUrl
            ? FcmNotification::create($title, $body, $imageUrl)
            : FcmNotification::create($title, $body);

        // Custom notification tone — the admin picks one of the tones
        // BUNDLED in the app (System Settings → App → notification tone).
        // Applied ONLY when the caller marked this message's recipients as
        // tone-capable (app_version ≥ CUSTOM_TONE_MIN_VERSION): the sound
        // files + Android channels only exist in those builds, and on older
        // Android builds a push aimed at an unknown channel_id is silently
        // DROPPED. Channel ids and sound names must match
        // PushNotificationService in the Flutter app (channel
        // 'temple_{tone}_v1', raw resource '{tone}', iOS '{tone}.caf' in
        // the Runner bundle).
        $tone = $customToneCapable ? $this->customToneConfigured() : null;
        $customSound = $tone !== null;
        $androidChannel = $customSound ? "temple_{$tone}_v1" : 'temple_default';
        $androidSound = $customSound ? $tone : 'default';
        $apnsSound = $customSound ? "{$tone}.caf" : 'default';

        $message = CloudMessage::new()
            ->withNotification($notification)
            ->withAndroidConfig(AndroidConfig::fromArray([
                'priority' => 'high',
                'notification' => array_filter([
                    'channel_id' => $androidChannel,
                    'sound' => $androidSound,
                    'image' => $imageUrl,
                ], fn ($v) => $v !== null),
            ]))
            ->withApnsConfig(ApnsConfig::fromArray(array_filter([
                'headers' => [
                    'apns-priority' => '10',
                ],
                'payload' => [
                    'aps' => array_filter([
                        'sound' => $apnsSound,
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
