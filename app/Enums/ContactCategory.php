<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What kind of message a devotee is sending through the contact form.
 *
 * Kept as an enum rather than a free-text field so the trust can filter the
 * inbox ("show me every suggestion") and so the three surfaces — website, app
 * and admin — cannot drift into different spellings of the same thing.
 */
enum ContactCategory: string
{
    case QUERY = 'query';
    case SUGGESTION = 'suggestion';
    case FEEDBACK = 'feedback';
    case COMPLAINT = 'complaint';
    case SEVA_REQUEST = 'seva_request';
    case OTHER = 'other';

    /** Translation key for the reader-facing label. */
    public function labelKey(): string
    {
        return "contact.category_{$this->value}";
    }

    public function label(): string
    {
        return __($this->labelKey());
    }

    /**
     * value => translated label, for a <select> / dropdown on any surface.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
