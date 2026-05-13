<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\AdminUser;
use App\Models\NotificationTemplate;
use App\Models\SystemSetting;
use Illuminate\Database\Eloquent\Model;

/**
 * Translates a template's recipient_strategy + recipient_value into a
 * concrete address (email or phone) given the dispatch context.
 *
 * Returns null if the strategy cannot be satisfied — the caller should
 * skip delivery and log; missing recipients are normal during testing
 * and in environments where credentials aren't configured yet.
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
            NotificationTemplate::RECIPIENT_DEVOTEE => $this->fromDevotee($context, $expectedType),
            NotificationTemplate::RECIPIENT_TRUST_ADMIN => $this->fromTrustAdmin($expectedType),
            NotificationTemplate::RECIPIENT_FIXED_EMAIL => $expectedType === 'email'
                ? $template->recipient_value
                : null,
            NotificationTemplate::RECIPIENT_FIXED_PHONE => $expectedType === 'phone'
                ? $template->recipient_value
                : null,
            NotificationTemplate::RECIPIENT_CONTEXT_PATH => $template->recipient_value
                ? $context->get($template->recipient_value)
                : null,
            NotificationTemplate::RECIPIENT_ADMIN_USER => $this->fromAdminUser(
                $template->recipient_value,
                $expectedType,
            ),
            default => null,
        };

        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return ['type' => $expectedType, 'value' => trim($value)];
    }

    private function fromDevotee(NotificationContext $context, string $expectedType): ?string
    {
        $devotee = $context->get('devotee');
        if ($devotee instanceof Model) {
            return $expectedType === 'email'
                ? ($devotee->getAttribute('email') ?: null)
                : ($devotee->getAttribute('phone') ?: null);
        }
        // Fallback to top-level convenience keys: $context['email'] / ['phone'].
        return $context->get($expectedType);
    }

    private function fromTrustAdmin(string $expectedType): ?string
    {
        $key = $expectedType === 'email' ? 'trust_email' : 'trust_phone';
        $value = SystemSetting::getValue($key, '');
        return $value !== '' ? $value : null;
    }

    /**
     * Resolve a specific AdminUser's email / phone by their id stored
     * in template.recipient_value. Returns null when:
     *   • no recipient_value is set
     *   • the user row doesn't exist (deleted since template was saved)
     *   • the user has no email / phone for the requested channel
     * — same null-returns-skip contract the rest of the resolver uses.
     */
    private function fromAdminUser(?string $userId, string $expectedType): ?string
    {
        if ($userId === null || $userId === '') return null;
        $user = AdminUser::find($userId);
        if (! $user) return null;
        $field = $expectedType === 'email' ? 'email' : 'phone';
        $value = $user->{$field};
        return is_string($value) && trim($value) !== '' ? $value : null;
    }
}
