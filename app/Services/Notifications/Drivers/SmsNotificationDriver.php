<?php

declare(strict_types=1);

namespace App\Services\Notifications\Drivers;

use App\Models\NotificationTemplate;
use App\Services\Notifications\Contracts\NotificationDriver;
use App\Services\Notifications\NotificationContext;
use App\Services\Notifications\RecipientResolver;
use App\Services\SmsService;
use Illuminate\Support\Facades\Log;

/**
 * SMS channel — DLT-approved template messages via MSG91 Flow API.
 *
 * Convention for the admin-managed placeholder_map on SMS rows:
 *   • Keys MUST be named var1, var2, var3 … (matching MSG91's variable
 *     names, which in turn map to DLT's positional {#var#} markers).
 *   • Values are dot-paths into the dispatch context as usual.
 *
 *   Example for auth.otp:
 *     sms_template_id  → "6512abc..."   (paste from MSG91 dashboard)
 *     placeholder_map  → { "var1": "otp" }
 *
 *   When OtpService::generate dispatches with `['otp' => '128765', ...]`,
 *   the driver sends MSG91 the recipient `{ mobiles: ..., var1: '128765' }`
 *   and MSG91 fills the {#var#} in the approved template.
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

        // Build the var1/var2/... map by resolving each placeholder_map
        // value as a dot-path against the context. Sorted by key so
        // var1 is always sent before var2 even if the admin entered
        // them out of order in the KeyValue editor.
        $rawMap = (array) ($template->placeholder_map ?? []);
        ksort($rawMap);
        $variables = [];
        foreach ($rawMap as $key => $path) {
            if (! is_string($key) || ! str_starts_with($key, 'var')) continue;
            $value = $context->get((string) $path, '');
            // MSG91 expects scalar values — never inline a structure.
            // If a context lookup landed on an Eloquent collection or
            // a nested array the previous code silently sent an empty
            // string. Log it so the admin can see they picked the wrong
            // dot-path (e.g. "donation.devotee" instead of
            // "donation.devotee.name").
            if (is_array($value) || is_object($value)) {
                Log::warning('Notification: SMS variable resolved to non-scalar — sending empty', [
                    'template_key' => $template->key,
                    'sms_variable' => $key,
                    'context_path' => $path,
                    'value_type' => is_object($value) ? get_class($value) : 'array',
                ]);
                $value = '';
            } elseif ($value === '' || $value === null) {
                Log::warning('Notification: SMS variable resolved to empty', [
                    'template_key' => $template->key,
                    'sms_variable' => $key,
                    'context_path' => $path,
                ]);
                $value = '';
            }
            $variables[$key] = (string) $value;
        }

        $result = $this->sms->sendTemplate(
            $recipient['value'],
            $templateId,
            $variables,
        );

        if (! $result['ok']) {
            Log::error('Notification: SMS send failed', [
                'template_key' => $template->key,
                'message' => $result['message'],
            ]);
            return false;
        }
        return true;
    }
}
