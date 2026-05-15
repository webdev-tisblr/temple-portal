<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\AdminUser;
use App\Models\NotificationTemplate;
use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Translates a template's recipient_strategy + recipient_value into a
 * concrete address (email or phone) given the dispatch context.
 *
 * Returns null when the strategy cannot be satisfied — callers (drivers
 * and NotificationService) treat null as "skip + log". Every null path
 * also writes a Log::warning so admins can spot misconfigured templates
 * in production logs even when the notification_logs row only says
 * "skipped: no recipient".
 */
final class RecipientResolver
{
    /** @return array{type: 'email'|'phone', value: string}|null */
    public function resolve(
        NotificationTemplate $template,
        NotificationContext $context,
        string $expectedType,
    ): ?array {
        $value = match ($template->recipient_strategy) {
            NotificationTemplate::RECIPIENT_DEVOTEE => $this->fromDevotee($template, $context, $expectedType),
            NotificationTemplate::RECIPIENT_TRUST_ADMIN => $this->fromTrustAdmin($template, $expectedType),
            NotificationTemplate::RECIPIENT_FIXED_EMAIL => $this->fromFixedEmail($template, $expectedType),
            NotificationTemplate::RECIPIENT_FIXED_PHONE => $this->fromFixedPhone($template, $expectedType),
            NotificationTemplate::RECIPIENT_CONTEXT_PATH => $this->fromContextPath($template, $context),
            NotificationTemplate::RECIPIENT_ADMIN_USER => $this->fromAdminUser(
                $template,
                $template->recipient_value,
                $expectedType,
            ),
            NotificationTemplate::RECIPIENT_ADMIN_ROLE => $this->fromAdminInContext($template, $context, $expectedType),
            default => $this->warnUnknownStrategy($template),
        };

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return ['type' => $expectedType, 'value' => trim($value)];
    }

    private function fromDevotee(NotificationTemplate $template, NotificationContext $context, string $expectedType): ?string
    {
        $devotee = $context->get('devotee');
        if ($devotee instanceof Model) {
            $value = $expectedType === 'email'
                ? ($devotee->getAttribute('email') ?: null)
                : ($devotee->getAttribute('phone') ?: null);

            if ($value === null) {
                Log::warning('Notification: devotee has no ' . $expectedType, [
                    'template_key' => $template->key,
                    'channel' => $template->channel,
                    'devotee_id' => $devotee->getKey(),
                ]);
            }
            return $value;
        }

        // Fallback to top-level convenience keys: $context['email'] / ['phone'].
        $value = $context->get($expectedType);
        if (! is_string($value) || trim($value) === '') {
            Log::warning('Notification: recipient_strategy=devotee but no devotee/' . $expectedType . ' in context', [
                'template_key' => $template->key,
                'channel' => $template->channel,
            ]);
            return null;
        }
        return $value;
    }

    private function fromTrustAdmin(NotificationTemplate $template, string $expectedType): ?string
    {
        $key = $expectedType === 'email' ? 'trust_email' : 'trust_phone';
        $value = SystemSetting::getValue($key, '');
        if ($value === '') {
            Log::warning('Notification: trust_admin strategy but ' . $key . ' SystemSetting is blank', [
                'template_key' => $template->key,
                'channel' => $template->channel,
            ]);
            return null;
        }
        return $value;
    }

    private function fromFixedEmail(NotificationTemplate $template, string $expectedType): ?string
    {
        if ($expectedType !== 'email') {
            Log::warning('Notification: fixed_email strategy used on non-email channel', [
                'template_key' => $template->key,
                'channel' => $template->channel,
            ]);
            return null;
        }
        return $template->recipient_value;
    }

    private function fromFixedPhone(NotificationTemplate $template, string $expectedType): ?string
    {
        if ($expectedType !== 'phone') {
            Log::warning('Notification: fixed_phone strategy used on non-phone channel', [
                'template_key' => $template->key,
                'channel' => $template->channel,
            ]);
            return null;
        }
        return $template->recipient_value;
    }

    private function fromContextPath(NotificationTemplate $template, NotificationContext $context): ?string
    {
        $path = $template->recipient_value;
        if ($path === null || $path === '') {
            Log::warning('Notification: context_path strategy has empty recipient_value', [
                'template_key' => $template->key,
                'channel' => $template->channel,
            ]);
            return null;
        }
        $value = $context->get($path);
        if (! is_string($value) || trim($value) === '') {
            Log::warning('Notification: context_path resolved to empty/non-string value', [
                'template_key' => $template->key,
                'channel' => $template->channel,
                'path' => $path,
            ]);
            return null;
        }
        return $value;
    }

    /**
     * Resolve a specific AdminUser's email / phone by their id stored
     * in template.recipient_value. Returns null when:
     *   • no recipient_value is set
     *   • the user row doesn't exist (deleted since template was saved)
     *   • the user has no email / phone for the requested channel
     * — same null-returns-skip contract the rest of the resolver uses.
     */
    private function fromAdminUser(NotificationTemplate $template, ?string $userId, string $expectedType): ?string
    {
        if ($userId === null || $userId === '') {
            Log::warning('Notification: admin_user strategy has empty recipient_value', [
                'template_key' => $template->key,
                'channel' => $template->channel,
            ]);
            return null;
        }

        $user = AdminUser::find($userId);
        if (! $user) {
            Log::warning('Notification: admin_user id not found (deleted?)', [
                'template_key' => $template->key,
                'channel' => $template->channel,
                'admin_user_id' => $userId,
            ]);
            return null;
        }

        $field = $expectedType === 'email' ? 'email' : 'phone';
        $value = $user->{$field};
        if (! is_string($value) || trim($value) === '') {
            Log::warning('Notification: admin_user has no ' . $expectedType, [
                'template_key' => $template->key,
                'channel' => $template->channel,
                'admin_user_id' => $userId,
            ]);
            return null;
        }
        return $value;
    }

    /**
     * Resolve the email/phone of the `admin` injected into the context
     * by NotificationService's admin_role fan-out. The service has
     * already expanded the role into N per-admin deliveries — at this
     * point we just need to read from the current delivery's context.
     */
    private function fromAdminInContext(NotificationTemplate $template, NotificationContext $context, string $expectedType): ?string
    {
        $admin = $context->get('admin');
        if (! $admin instanceof AdminUser) {
            Log::warning('Notification: admin_role strategy but no admin in context (service-level fan-out misfire?)', [
                'template_key' => $template->key,
                'channel' => $template->channel,
                'role' => $template->recipient_value,
            ]);
            return null;
        }

        $field = $expectedType === 'email' ? 'email' : 'phone';
        $value = $admin->{$field};
        if (! is_string($value) || trim($value) === '') {
            Log::warning('Notification: admin_role member has no ' . $expectedType, [
                'template_key' => $template->key,
                'channel' => $template->channel,
                'admin_user_id' => $admin->getKey(),
                'role' => $template->recipient_value,
            ]);
            return null;
        }
        return $value;
    }

    private function warnUnknownStrategy(NotificationTemplate $template): ?string
    {
        Log::warning('Notification: unknown recipient_strategy', [
            'template_key' => $template->key,
            'channel' => $template->channel,
            'strategy' => $template->recipient_strategy,
        ]);
        return null;
    }
}
