<?php

declare(strict_types=1);

namespace App\Services\Notifications\Drivers;

use App\Models\NotificationTemplate;
use App\Services\Notifications\Contracts\NotificationDriver;
use App\Services\Notifications\NotificationContext;
use App\Services\Notifications\RecipientResolver;
use App\Services\OtpService;
use App\Services\SmsService;
use Illuminate\Support\Facades\Log;

/**
 * SMS channel — DLT-approved template messages via MSG91 Flow API.
 *
 * Convention for the admin-managed placeholder_map on SMS rows:
 *   • Keys are the VARIABLE NAMES from the DLT-approved template — the
 *     text between the ##…## markers. MSG91 Flow matches by name, not by
 *     position.
 *   • Values are dot-paths into the dispatch context as usual.
 *
 *   Example, for the trust's approved template
 *   ("… is ##OTP##. This OTP is valid for ##mins## minutes …"):
 *     sms_template_id  → "6512abc..."   (paste from MSG91 dashboard)
 *     placeholder_map  → { "OTP": "otp", "mins": "expires_in_minutes" }
 *
 *   When OtpService::generate dispatches with `['otp' => '128765', ...]`,
 *   the driver sends MSG91 the recipient
 *   `{ mobiles: ..., OTP: '128765', mins: '10' }`.
 *
 * LEGACY var1/var2 KEYS
 * ---------------------
 * Older rows (including the shipped auth.otp seed) use `var1`, `var2` …
 * That is MSG91's *positional* convention and it only works for templates
 * whose markers are literally named var1/var2. The trust's template is
 * not one of those, which is why no OTP SMS ever arrived: MSG91 filled
 * nothing and rejected the submission with "Template ID Missing or
 * Invalid Template" — behind an HTTP 200 {"type":"success"}, so the send
 * path saw a success.
 *
 * For `auth.otp` specifically, positional keys are therefore translated
 * at send time into the names configured in System Settings → SMS
 * (defaulting to OTP / mins). Any row that uses real names is passed
 * through untouched — naming a variable wins over the compatibility path.
 */
final class SmsNotificationDriver implements NotificationDriver
{
    public function __construct(
        private readonly RecipientResolver $recipients,
        private readonly SmsService $sms,
    ) {
    }

    public function channel(): string
    {
        return NotificationTemplate::CHANNEL_SMS;
    }

    /**
     * MSG91's request id for the last submission.
     *
     * NotificationService::deliver() probes for this with method_exists()
     * and stores it on temple_notification_logs.provider_message_id — the
     * join key the inbound delivery report matches on.
     */
    public function lastMessageId(): ?string
    {
        return $this->sms->lastMessageId();
    }

    public function send(NotificationTemplate $template, NotificationContext $context): bool
    {
        // Recipient phone — strategies handled by RecipientResolver. The
        // common case for OTPs is recipient_strategy = context_path with
        // recipient_value = "phone" (matches OtpService dispatch).
        $recipient = $this->recipients->resolve($template, $context, 'phone');
        if ($recipient === null) {
            Log::warning('Notification: SMS recipient unresolved', [
                'template_key' => $template->key,
                'recipient_strategy' => $template->recipient_strategy,
            ]);
            return false;
        }

        // Template id: per-row override OR fall back to the system-wide
        // OTP template id (covers the auth.otp default seed without
        // forcing an admin to paste the same id twice).
        $templateId = $template->sms_template_id
            ?: $this->sms->getOtpTemplateId();

        if ($templateId === '') {
            Log::warning('Notification: SMS template id missing', [
                'template_key' => $template->key,
            ]);
            return false;
        }

        // Build the variable map by resolving each placeholder_map value
        // as a dot-path against the context. Keys are passed through as
        // the MSG91 variable names verbatim (sorted only so the outgoing
        // JSON is stable and diffable).
        $rawMap = (array) ($template->placeholder_map ?? []);
        ksort($rawMap);
        $variables = [];
        foreach ($rawMap as $key => $path) {
            // Any valid MSG91 variable name is accepted. The old filter
            // here was `str_starts_with($key, 'var')`, which silently
            // DROPPED every correctly-named variable — an admin who typed
            // the real name from their DLT template got an empty SMS body
            // and no warning anywhere.
            if (! is_string($key) || ! preg_match('/^[A-Za-z0-9_]+$/', $key)) continue;
            // MSG91 expects scalar values. Route through the shared
            // display coercion so dates, enums and TIME columns come
            // out formatted the same as every other channel — a raw
            // (string) cast here turned Carbon-cast columns like
            // booking_date into empty strings.
            $raw = $context->get((string) $path);
            $value = NotificationContext::formatForDisplay($raw);
            if ($value === '') {
                // Either the field is genuinely empty or the admin
                // picked a dot-path that landed on a structure (e.g.
                // "donation.devotee" instead of "donation.devotee.name")
                // — value_type tells them which.
                Log::warning('Notification: SMS variable resolved to empty', [
                    'template_key' => $template->key,
                    'sms_variable' => $key,
                    'context_path' => $path,
                    'value_type' => is_object($raw) ? get_class($raw) : gettype($raw),
                ]);
            }
            $variables[$key] = $value;
        }

        $variables = $this->applyOtpVariableNames($template, $variables);

        // auth.otp goes through MSG91's OTP service, not Flow. They are
        // separate products with separate template libraries, and an OTP
        // template id is rejected by /flow/ as "Template ID Missing or
        // Invalid Template" — which is what stopped every login OTP
        // (2026-08-10). Everything else is ordinary transactional SMS and
        // belongs on Flow.
        if ($template->key === 'auth.otp') {
            // The code comes from the CONTEXT, not from guessing which
            // variable name holds it. OtpService dispatches the code it
            // stored and will verify, so that is the authoritative value;
            // an admin free to name their template variable anything
            // (PASSCODE, code, …) must not be able to break the send by
            // choosing a name the settings do not know about.
            $code = NotificationContext::formatForDisplay($context->get('otp'));

            if ($code === '') {
                Log::warning('Notification: OTP code missing from dispatch context', [
                    'template_key' => $template->key,
                    'resolved_variables' => array_keys($variables),
                ]);

                return false;
            }

            // Every mapped variable still rides along by name. MSG91 fills
            // ##OTP## from its own `otp` param and any other placeholder
            // from the matching variable, so both naming styles work and
            // neither needs detecting.
            //
            // The validity placeholder is GUARANTEED, though. The live
            // row mapped it as `expires_in_minutes` while the DLT template
            // asks for ##mins##, so the SMS went out reading "valid for
            //  minutes" with a hole where the number belongs (2026-08-10).
            // A missing number in a security message is worth defending
            // against centrally rather than relying on every admin to name
            // the variable exactly right.
            $validityName = $this->sms->otpValidityVariableName();
            if (($variables[$validityName] ?? '') === '') {
                $variables[$validityName] = (string) OtpService::expiryMinutes();
            }

            $result = $this->sms->sendOtp($recipient['value'], $code, $variables, $templateId);
        } else {
            $result = $this->sms->sendTemplate(
                $recipient['value'],
                $templateId,
                $variables,
            );
        }

        if (! $result['ok']) {
            Log::error('Notification: SMS send failed', [
                'template_key' => $template->key,
                'message' => $result['message'],
            ]);
            return false;
        }
        return true;
    }

    /**
     * Compatibility path for auth.otp rows still carrying MSG91's
     * positional var1/var2 keys.
     *
     * The trust's DLT template names its markers ##OTP## and ##mins##, so
     * a payload of {var1, var2} fills nothing and the submission is
     * rejected. Rather than hardcode OTP/mins here — which would spring
     * the identical trap on the next template the trust registers — the
     * names come from System Settings → SMS, defaulting to the two the
     * current template uses so the trust has to configure nothing.
     *
     * Untouched when the admin has authored real variable names: an
     * explicit name always beats this fallback. Also untouched for any
     * trigger other than auth.otp, where there is no well-known meaning
     * for "first variable".
     *
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    private function applyOtpVariableNames(NotificationTemplate $template, array $variables): array
    {
        if ($template->key !== 'auth.otp') {
            return $variables;
        }

        // A key that is not varN means the admin named their variables —
        // respect that completely and do nothing here.
        foreach (array_keys($variables) as $key) {
            if (! preg_match('/^var\d+$/i', (string) $key)) {
                return $variables;
            }
        }

        $otpName = $this->sms->otpVariableName();
        $validityName = $this->sms->otpValidityVariableName();

        // var1 = the code, var2 = the validity window; that is the order
        // the shipped seed and the DLT template both use. Fall back to
        // the enforced expiry when the row never mapped a second
        // variable, so the SMS cannot promise a window the server does
        // not honour.
        $renamed = [
            $otpName => $variables['var1'] ?? '',
            $validityName => $variables['var2'] ?? (string) OtpService::expiryMinutes(),
        ];

        Log::info('Notification: SMS OTP variables renamed from positional keys', [
            'template_key' => $template->key,
            'from' => array_keys($variables),
            'to' => array_keys($renamed),
        ]);

        return $renamed;
    }
}
