<?php

declare(strict_types=1);

namespace App\Models\Concerns;

/**
 * Admin-defined dynamic form fields, and the one rule for resolving their
 * labels into the reader's language.
 *
 * Extracted from DonationType 2026-08-13 when sevas and campaigns gained the
 * same feature. The label fallback chain lives here so the three models — and
 * therefore the website, the app and the greeting-card editor — can never
 * disagree about what a field is called.
 *
 * Requires: an `extra_fields` array cast on the model.
 */
trait HasExtraFields
{
    /**
     * `extra_fields` with a resolved `label` on every entry.
     *
     * Only `label_gu` and `label_en` are required in the admin, so `label_hi`
     * is routinely blank. Consumers read `label` and never the `label_*`
     * columns, exactly as they already read `name` rather than `name_gu`.
     *
     * @return array<int, array<string, mixed>>
     */
    public function localizedExtraFields(?string $locale = null): array
    {
        $locale ??= app()->getLocale();

        return collect($this->extra_fields ?? [])
            ->filter(fn ($field) => is_array($field))
            ->map(function (array $field) use ($locale): array {
                $localized = match ($locale) {
                    'hi' => $field['label_hi'] ?? null,
                    'en' => $field['label_en'] ?? null,
                    default => null,
                };

                $field['label'] = (string) (filled($localized)
                    ? $localized
                    : ($field['label_gu'] ?? $field['label_en'] ?? $field['key'] ?? ''));

                return $field;
            })
            ->values()
            ->all();
    }

    /** Field definitions that accept an uploaded image. */
    public function imageExtraFieldKeys(): array
    {
        return collect($this->extra_fields ?? [])
            ->filter(fn ($f) => is_array($f) && ($f['type'] ?? null) === 'image')
            ->pluck('key')
            ->filter()
            ->values()
            ->all();
    }
}
