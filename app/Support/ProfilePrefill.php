<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Devotee;

/**
 * Fills a checkout form's text fields from the devotee's saved profile.
 *
 * Donation types, campaigns and sevas can each define dynamic "extra fields"
 * asked at checkout, and in practice most of them ask for something the trust
 * already knows: the devotee's name, their birthday, the village to print on a
 * card. Retyping it on every booking is the single most-repeated bit of work in
 * the app, and it is also where the data goes wrong — a name spelt three ways
 * across three bookings is three different names on three greeting cards.
 *
 * An admin marks a field with a profile SOURCE; the value is then filled in for
 * a logged-in devotee and left editable, because a devotee booking a seva in a
 * relative's name must still be able to type over it. Guests get nothing, which
 * is the same thing an unmarked field gets.
 *
 * Photo fields are deliberately NOT covered: the profile photo is an avatar,
 * not the picture someone wants printed on a card, so image uploads stay
 * manual (decided 2026-08-29).
 */
final class ProfilePrefill
{
    /**
     * Profile attributes a field may be filled from, with the admin-facing
     * label. Keys are stored in the field definition as `prefill_from`.
     *
     * @return array<string, string>
     */
    public static function sources(): array
    {
        return [
            'name' => 'Devotee name',
            'phone' => 'Phone number',
            'email' => 'Email address',
            'date_of_birth' => 'Date of birth',
            'address' => 'Address',
            'city' => 'City / village',
            'state' => 'State',
            'pincode' => 'PIN code',
        ];
    }

    /**
     * The devotee's value for one source, or null when there is no devotee,
     * the source is unknown, or the profile simply has not got it yet.
     */
    public static function valueFor(?Devotee $devotee, ?string $source): ?string
    {
        if ($devotee === null || ! filled($source) || ! array_key_exists($source, self::sources())) {
            return null;
        }

        // date_of_birth is a Carbon cast; a date input wants Y-m-d, and every
        // other source is already a plain string.
        $value = $source === 'date_of_birth'
            ? $devotee->date_of_birth?->toDateString()
            : $devotee->getAttribute($source);

        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value !== '' ? $value : null;
    }

    /**
     * field key => prefilled value, for every extra field that names a
     * profile source and has one to offer. Keys with nothing to fill are
     * absent rather than null, so a caller can `?? old(...)` without
     * clobbering what the devotee already typed.
     *
     * @param  array<int, array<string, mixed>>|null  $extraFields
     * @return array<string, string>
     */
    public static function values(?array $extraFields, ?Devotee $devotee): array
    {
        if ($devotee === null) {
            return [];
        }

        $out = [];

        foreach ($extraFields ?? [] as $field) {
            if (! is_array($field) || ! filled($field['key'] ?? null)) {
                continue;
            }

            // An image field can carry a stale prefill_from from before the
            // type was changed; never answer a photo question with text.
            if (($field['type'] ?? 'text') === 'image') {
                continue;
            }

            $value = self::valueFor($devotee, $field['prefill_from'] ?? null);
            if ($value !== null) {
                $out[(string) $field['key']] = $value;
            }
        }

        return $out;
    }

    /**
     * Extra-field definitions with each one's prefilled value attached as
     * `prefill`, for the API. Shape is otherwise untouched, so an older app
     * build that does not know the key simply ignores it.
     *
     * @param  array<int, array<string, mixed>>  $fields
     * @return array<int, array<string, mixed>>
     */
    public static function decorate(array $fields, ?Devotee $devotee): array
    {
        $values = self::values($fields, $devotee);

        return collect($fields)
            ->map(function ($field) use ($values) {
                if (is_array($field) && filled($field['key'] ?? null)) {
                    $field['prefill'] = $values[(string) $field['key']] ?? null;
                }

                return $field;
            })
            ->values()
            ->all();
    }
}
