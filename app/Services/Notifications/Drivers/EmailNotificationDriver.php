<?php

declare(strict_types=1);

namespace App\Services\Notifications\Drivers;

use App\Models\NotificationTemplate;
use App\Models\SystemSetting;
use App\Services\Notifications\Contracts\NotificationDriver;
use App\Services\Notifications\NotificationContext;
use App\Services\Notifications\RecipientResolver;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Email channel — renders subject + body against the dispatch context
 * and delivers via Laravel Mail (which is bootstrapped from
 * temple_system_settings.mail_* values, so SMTP edits in the admin
 * page take effect without redeploy).
 */
final class EmailNotificationDriver implements NotificationDriver
{
    public function __construct(private readonly RecipientResolver $recipients)
    {
    }

    public function channel(): string
    {
        return NotificationTemplate::CHANNEL_EMAIL;
    }

    public function send(NotificationTemplate $template, NotificationContext $context): bool
    {
        $recipient = $this->recipients->resolve($template, $context, 'email');
        if ($recipient === null) {
            Log::warning('Notification: email recipient unresolved', [
                'template_key' => $template->key,
                'recipient_strategy' => $template->recipient_strategy,
            ]);
            return false;
        }

        $subject = $context->render($template->subject ?? '', $template->placeholder_map ?? []);
        $body = $context->render($template->body ?? '', $template->placeholder_map ?? []);

        if (trim($subject) === '' || trim($body) === '') {
            Log::warning('Notification: email subject/body empty after render', [
                'template_key' => $template->key,
            ]);
            return false;
        }

        $fromAddress = $template->from_address
            ?: SystemSetting::getValue('mail_from_address', config('mail.from.address') ?? '');
        $fromName = $template->from_name
            ?: SystemSetting::getValue('mail_from_name', config('mail.from.name') ?? '');

        // Optional attachments — pass via context['_attachments'] as
        //   [['data' => bytes, 'name' => '…pdf', 'mime' => 'application/pdf']]
        // — keeps the attachment story consistent across triggers.
        $attachments = $context->get('_attachments', []);

        // Retry up to 3 times with linear backoff to absorb transient
        // SMTP failures (Hostinger's relay sometimes refuses the first
        // connect after an idle period, the second succeeds). 10s SMTP
        // timeout + 3 tries fits within Hostinger's 60s PHP execution
        // budget even worst-case (10s + 1s + 10s + 2s + 10s = 33s).
        // Without this layer a single transient blip silently lost
        // the notification — the "sometimes mails come, sometimes
        // not" symptom the trust admin reported.
        $maxAttempts = 3;
        $lastError = null;

        for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
            try {
                Mail::html($body, function (Message $message) use ($recipient, $subject, $fromAddress, $fromName, $attachments) {
                    if ($fromAddress) {
                        $message->from($fromAddress, $fromName ?: null);
                    }
                    $message->to($recipient['value']);
                    $message->subject($subject);

                    if (is_array($attachments)) {
                        foreach ($attachments as $att) {
                            if (! is_array($att) || empty($att['data']) || empty($att['name'])) continue;
                            $message->attachData(
                                (string) $att['data'],
                                (string) $att['name'],
                                ['mime' => $att['mime'] ?? 'application/octet-stream']
                            );
                        }
                    }
                });

                if ($attempt > 1) {
                    Log::info('Notification: email send succeeded on retry', [
                        'template_key' => $template->key,
                        'to' => $recipient['value'],
                        'attempt' => $attempt,
                    ]);
                }
                return true;
            } catch (\Throwable $e) {
                $lastError = $e;
                Log::warning('Notification: email send attempt failed', [
                    'template_key' => $template->key,
                    'to' => $recipient['value'],
                    'attempt' => $attempt,
                    'error' => $e->getMessage(),
                ]);

                if ($attempt < $maxAttempts) {
                    // Linear backoff: 1s, 2s. Short enough to fit in
                    // a sync request but long enough that a transient
                    // SMTP refusal has time to clear.
                    usleep($attempt * 1_000_000);
                }
            }
        }

        Log::error('Notification: email send permanently failed', [
            'template_key' => $template->key,
            'to' => $recipient['value'],
            'attempts' => $maxAttempts,
            'final_error' => $lastError?->getMessage(),
        ]);
        return false;
    }
}
