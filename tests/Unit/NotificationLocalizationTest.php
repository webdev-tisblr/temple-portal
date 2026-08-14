<?php

namespace Tests\Unit;

use App\Services\Notifications\NotificationContext;
use App\Support\DurationLabel;
use PHPUnit\Framework\TestCase;

/**
 * Placeholder values must come out in the same language as the sentence
 * they land in (2026-08-14).
 *
 * Before this, every dispatch site pinned the `_gu` column and both
 * reminder schedulers hardcoded English units, so a Hindi reminder body
 * read "સુંદરકાંડ પાઠ की बुकिंग 3 hours में है" — two scripts and an
 * English unit in one sentence.
 */
class NotificationLocalizationTest extends TestCase
{
    public function test_it_prefers_the_recipients_language_for_a_gu_path(): void
    {
        $context = new NotificationContext([
            'locale' => 'hi',
            'booking' => ['seva' => [
                'name_gu' => 'સુંદરકાંડ પાઠ',
                'name_hi' => 'सुंदरकांड पाठ',
                'name_en' => 'Sundarkand Path',
            ]],
        ]);

        $this->assertSame('सुंदरकांड पाठ', $context->getForDisplay('booking.seva.name_gu'));
    }

    public function test_it_upgrades_a_bare_path_to_the_language_sibling(): void
    {
        // The other shape in production: the dispatch site pre-resolved a
        // scalar, so the template maps `booking.seva_name` with no suffix.
        $context = new NotificationContext([
            'locale' => 'en',
            'booking' => [
                'seva_name' => 'સુંદરકાંડ પાઠ',
                'seva_name_hi' => 'सुंदरकांड पाठ',
                'seva_name_en' => 'Sundarkand Path',
            ],
        ]);

        $this->assertSame('Sundarkand Path', $context->getForDisplay('booking.seva_name'));
    }

    public function test_it_falls_back_to_gujarati_when_the_translation_is_blank(): void
    {
        // An untranslated seva must not render an empty parameter — Meta
        // rejects the whole WhatsApp send when one resolves to "".
        $context = new NotificationContext([
            'locale' => 'hi',
            'booking' => ['seva' => ['name_gu' => 'સુંદરકાંડ પાઠ', 'name_hi' => '   ']],
        ]);

        $this->assertSame('સુંદરકાંડ પાઠ', $context->getForDisplay('booking.seva.name_gu'));
    }

    public function test_locale_falls_back_to_the_devotees_saved_language(): void
    {
        $context = new NotificationContext([
            'devotee' => ['language' => 'hi'],
        ]);

        $this->assertSame('hi', $context->locale());
    }

    public function test_locale_defaults_to_gujarati_for_an_unknown_value(): void
    {
        $this->assertSame('gu', (new NotificationContext(['locale' => 'fr']))->locale());
        $this->assertSame('gu', (new NotificationContext([]))->locale());
    }

    public function test_duration_labels_carry_the_unit_in_each_language(): void
    {
        $this->assertSame('3 કલાક', DurationLabel::make(180, 'gu'));
        $this->assertSame('3 घंटे', DurationLabel::make(180, 'hi'));
        $this->assertSame('3 hours', DurationLabel::make(180, 'en'));

        // Hindi is the only one of the three that inflects the unit.
        $this->assertSame('1 घंटा', DurationLabel::make(60, 'hi'));
        $this->assertSame('1 કલાક', DurationLabel::make(60, 'gu'));
        $this->assertSame('1 hour', DurationLabel::make(60, 'en'));
    }

    public function test_it_picks_the_largest_unit_that_divides_exactly(): void
    {
        $this->assertSame('1 week', DurationLabel::make(10080, 'en'));
        $this->assertSame('2 days', DurationLabel::make(2880, 'en'));
        $this->assertSame('45 minutes', DurationLabel::make(45, 'en'));

        // 10 days is not a whole number of weeks, so it stays in days.
        $this->assertSame('10 days', DurationLabel::make(14400, 'en'));
    }

    public function test_context_values_publish_a_sibling_per_language(): void
    {
        $values = DurationLabel::contextValues(180);

        // The bare key holds Gujarati, matching the platform fallback, and
        // getLocalized() upgrades it per recipient with no map change.
        $this->assertSame('3 કલાક', $values['time_remaining_label']);
        $this->assertSame('3 કલાક', $values['time_remaining_label_gu']);
        $this->assertSame('3 घंटे', $values['time_remaining_label_hi']);
        $this->assertSame('3 hours', $values['time_remaining_label_en']);

        $context = new NotificationContext(array_merge(['locale' => 'hi'], $values));
        $this->assertSame('3 घंटे', $context->getForDisplay('time_remaining_label'));
    }

    public function test_rendering_substitutes_localized_values(): void
    {
        $context = new NotificationContext(array_merge([
            'locale' => 'hi',
            'booking' => ['seva' => ['name_gu' => 'સુંદરકાંડ પાઠ', 'name_hi' => 'सुंदरकांड पाठ']],
        ], DurationLabel::contextValues(180)));

        $rendered = $context->render(
            '{{ seva_name }} की बुकिंग {{ time_remaining_label }} में है',
            ['seva_name' => 'booking.seva.name_gu', 'time_remaining_label' => 'time_remaining_label'],
        );

        $this->assertSame('सुंदरकांड पाठ की बुकिंग 3 घंटे में है', $rendered);
    }

    public function test_gujarati_recipients_are_unaffected(): void
    {
        $context = new NotificationContext([
            'locale' => 'gu',
            'booking' => ['seva' => ['name_gu' => 'સુંદરકાંડ પાઠ', 'name_hi' => 'सुंदरकांड पाठ']],
        ]);

        $this->assertSame('સુંદરકાંડ પાઠ', $context->getForDisplay('booking.seva.name_gu'));
    }
}
