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
            return true;
        } catch (\Throwable $e) {
            Log::error('Notification: email send failed', [
                'template_key' => $template->key,
                'to' => $recipient['value'],
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }
}
