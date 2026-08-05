<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Services\SevaSlotService;
use Closure;
use Filament\Forms;
use Filament\Forms\Get;

/**
 * The slot-configuration form schema + its save/fill normalization,
 * shared by SevaResource (per-seva slot settings) and SlotPoolResource
 * (pool-owned slot settings for sevas that share capacity).
 *
 * Both owners store the exact same `slot_config` JSON (v2 schema), so
 * the fields, the flat transient helpers (`customize_{day}`,
 * `full_day_days`) and the normalization round-trip are identical.
 */
final class SlotConfigFields
{
    private const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /** @return array<int, \Filament\Forms\Components\Component> */
    public static function schema(): array
    {
        return [
            Forms\Components\Select::make('slot_config.slot_type')
                ->label('Booking Mode')
                ->options([
                    'time_slots' => 'Time slots — devotee picks a time',
                    'full_day' => 'Full day — the whole day is one booking (no time slot)',
                ])
                ->default('time_slots')
                ->live()
                ->required()
                ->helperText('Full-day sevas have no time slots — the day itself acts as the slot. Use "Available Days" below to restrict to specific weekdays.'),

            Forms\Components\Grid::make(2)->schema([
                Forms\Components\Select::make('slot_config.slot_duration_minutes')
                    ->label('Slot Duration')
                    ->options([
                        15 => '15 minutes',
                        30 => '30 minutes',
                        45 => '45 minutes',
                        60 => '1 hour',
                        90 => '1.5 hours',
                        120 => '2 hours',
                        180 => '3 hours',
                    ])
                    ->default(60)
                    ->visible(fn (Get $get) => ($get('slot_config.slot_type') ?? 'time_slots') === 'time_slots'),
                Forms\Components\TextInput::make('slot_config.max_bookings_per_slot')
                    ->label(fn (Get $get) => match ($get('slot_config.slot_type')) {
                        'full_day' => 'Max Bookings Per Day',
                        'full_week' => 'Max Bookings Per Week',
                        default => 'Max Bookings Per Slot',
                    })
                    ->numeric()->minValue(1)->default(1)
                    ->helperText('Capacity for each slot / day / week.'),
            ]),

            // Available days (full-day mode only) — compact checkbox row.
            // Uses a flat, transient field (mapped to/from
            // slot_config.full_day_days in prepareForFill/normalizeForSave).
            // A dotted state path here breaks Livewire's array-checkbox
            // binding — clicking one day toggles them all.
            Forms\Components\CheckboxList::make('full_day_days')
                ->label('Available on these days')
                ->options([
                    'monday' => 'Mon',
                    'tuesday' => 'Tue',
                    'wednesday' => 'Wed',
                    'thursday' => 'Thu',
                    'friday' => 'Fri',
                    'saturday' => 'Sat',
                    'sunday' => 'Sun',
                ])
                ->columns(['default' => 2, 'sm' => 4, 'lg' => 7])
                ->gridDirection('row')
                ->bulkToggleable()
                ->helperText('Leave all unchecked to offer this full-day seva every day; select days to restrict it.')
                ->visible(fn (Get $get) => ($get('slot_config.slot_type') ?? 'time_slots') === 'full_day'),

            // Reminder anchor (full-day / full-week only). These sevas
            // have no start time, so reminders count back from this
            // time on the booking day. Stored as "HH:MM" straight into
            // slot_config — a dotted TimePicker path binds fine (only
            // the CheckboxList above needed the flat-field workaround).
            Forms\Components\TimePicker::make('slot_config.reminder_anchor_time')
                ->label('Reminder Anchor Time')
                ->native(false)
                ->seconds(false)
                ->format('H:i')
                ->displayFormat('h:i A')
                ->helperText('Full-day sevas have no start time, so reminders (e.g. "3 hours before") are counted back from this time on the booking day. Leave blank to use the temple default (9:00 AM).')
                ->visible(fn (Get $get) => in_array(
                    $get('slot_config.slot_type'),
                    ['full_day', 'full_week'],
                    true,
                )),

            // Acceptance Period
            Forms\Components\Section::make('Acceptance Period')
                ->description('When is this seva open for booking?')
                ->collapsed()
                ->schema([
                    Forms\Components\Radio::make('slot_config.acceptance_period.type')
                        ->label('')
                        ->options([
                            'perpetual' => 'Always accepting bookings',
                            'range' => 'Specific date range',
                        ])
                        ->default('perpetual')
                        ->live()
                        ->inline(),
                    Forms\Components\DatePicker::make('slot_config.acceptance_period.start_date')
                        ->label('Start Date')
                        ->visible(fn (Get $get) => $get('slot_config.acceptance_period.type') === 'range'),
                    Forms\Components\DatePicker::make('slot_config.acceptance_period.end_date')
                        ->label('End Date')
                        ->visible(fn (Get $get) => $get('slot_config.acceptance_period.type') === 'range')
                        ->afterOrEqual('slot_config.acceptance_period.start_date'),
                ])->columns(3),

            // Weekly Schedule (time-slot mode only)
            Forms\Components\Section::make('Weekly Schedule')
                ->description('Set default time slots and override specific days if needed. Slots are validated against the duration to prevent overlaps.')
                ->visible(fn (Get $get) => ($get('slot_config.slot_type') ?? 'time_slots') === 'time_slots')
                ->schema([
                    Forms\Components\Repeater::make('slot_config.weekly_schedule.default')
                        ->label('Default Time Slots (all days)')
                        ->simple(
                            Forms\Components\TimePicker::make('time')
                                ->seconds(false)
                                ->required()
                                ->rules([
                                    fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get) {
                                        self::validateSlotOverlap($value, $get('../../slot_config.weekly_schedule.default'), (int) ($get('../../slot_config.slot_duration_minutes') ?? 60), $fail);
                                    },
                                ]),
                        )
                        ->defaultItems(0)
                        ->addActionLabel('Add Time Slot')
                        ->helperText('Each slot must not overlap with another based on the duration above.'),

                    Forms\Components\Fieldset::make('Day-Specific Overrides')
                        ->schema(
                            collect(self::DAYS)
                                ->flatMap(fn (string $day) => [
                                    Forms\Components\Toggle::make("customize_{$day}")
                                        ->label(ucfirst($day).' — custom schedule')
                                        ->inline()
                                        ->live(),
                                    Forms\Components\Repeater::make("slot_config.weekly_schedule.{$day}")
                                        ->label(ucfirst($day).' slots')
                                        ->simple(
                                            Forms\Components\TimePicker::make('time')
                                                ->seconds(false),
                                        )
                                        ->defaultItems(0)
                                        ->addActionLabel('Add Slot')
                                        ->visible(fn (Get $get) => (bool) $get("customize_{$day}"))
                                        ->helperText('No slots = closed on '.ucfirst($day).'.'),
                                ])->toArray()
                        )->columns(2),
                ]),

            // Blackout Dates
            Forms\Components\Repeater::make('slot_config.blackout_dates')
                ->label('Blackout Dates')
                ->helperText('Dates when this seva is not available, regardless of schedule.')
                ->schema([
                    Forms\Components\DatePicker::make('date')
                        ->label('Date')
                        ->required()
                        ->minDate(now()),
                    Forms\Components\TextInput::make('reason')
                        ->label('Reason')
                        ->required()
                        ->maxLength(255)
                        ->placeholder('e.g. Temple closed for renovation'),
                ])
                ->columns(2)
                ->collapsible()
                ->collapsed()
                ->defaultItems(0)
                ->addActionLabel('Add Blackout Date'),
        ];
    }

    /**
     * Fill-time preparation: normalize v1 → v2 config, seed the
     * `customize_{day}` toggles and the flat `full_day_days` checkbox
     * state from the stored JSON.
     */
    public static function prepareForFill(array $data): array
    {
        $config = app(SevaSlotService::class)->normalizeConfig($data['slot_config'] ?? null);
        $data['slot_config'] = $config;

        foreach (self::DAYS as $day) {
            $dayValue = $config['weekly_schedule'][$day] ?? null;
            $data["customize_{$day}"] = is_array($dayValue); // true if explicit array (even empty)
        }

        // Seed the flat full-day days checkbox field from slot_config.
        // Normalize a legacy {monday: bool, ...} map into a plain list.
        $storedDays = $config['full_day_days'] ?? [];
        if (is_array($storedDays) && $storedDays !== [] && array_keys($storedDays) !== range(0, count($storedDays) - 1)) {
            $storedDays = array_values(array_keys(array_filter($storedDays, fn ($v) => (bool) $v)));
        }
        $data['full_day_days'] = is_array($storedDays) ? array_values($storedDays) : [];

        return $data;
    }

    /**
     * Save-time normalization: clean/sort/dedupe slot times, fold the
     * transient day toggles + full-day checkbox back into slot_config,
     * stamp version 2.
     */
    public static function normalizeForSave(array $data): array
    {
        // Normalize default slots: trim seconds, sort, dedupe
        if (isset($data['slot_config']['weekly_schedule']['default'])) {
            $data['slot_config']['weekly_schedule']['default'] = self::cleanTimeSlots(
                $data['slot_config']['weekly_schedule']['default']
            );
        }

        // Process day overrides
        foreach (self::DAYS as $day) {
            $toggleKey = "customize_{$day}";

            if (empty($data[$toggleKey])) {
                $data['slot_config']['weekly_schedule'][$day] = null;
            } else {
                $slots = $data['slot_config']['weekly_schedule'][$day] ?? [];
                $data['slot_config']['weekly_schedule'][$day] = self::cleanTimeSlots($slots);
            }

            unset($data[$toggleKey]);
        }

        // Map the flat full-day days checkbox field back into slot_config
        // as a plain list of weekday names (empty = available every day).
        $fullDayDays = $data['full_day_days'] ?? [];
        $data['slot_config']['full_day_days'] = is_array($fullDayDays) ? array_values($fullDayDays) : [];
        unset($data['full_day_days']);

        // Stamp version
        if (! empty($data['slot_config'])) {
            $data['slot_config']['version'] = 2;
        }

        return $data;
    }

    /**
     * Validate that a slot time doesn't overlap with other slots given the duration.
     */
    public static function validateSlotOverlap($value, $allSlots, int $durationMinutes, Closure $fail): void
    {
        if (empty($value) || empty($allSlots) || ! is_array($allSlots)) {
            return;
        }

        $currentMinutes = self::timeToMinutes($value);
        if ($currentMinutes === null) {
            $fail('Invalid time format.');

            return;
        }

        $currentEnd = $currentMinutes + $durationMinutes;

        // Check for duplicates and overlaps
        $count = 0;
        foreach ($allSlots as $slot) {
            $slotTime = is_array($slot) ? ($slot['time'] ?? $slot) : $slot;
            $otherMinutes = self::timeToMinutes($slotTime);
            if ($otherMinutes === null) {
                continue;
            }

            // Count how many times this exact value appears
            if ($otherMinutes === $currentMinutes) {
                $count++;
                if ($count > 1) {
                    $fail("Duplicate slot time: {$value}.");

                    return;
                }

                continue;
            }

            $otherEnd = $otherMinutes + $durationMinutes;

            // Check overlap: two ranges [A, A+dur) and [B, B+dur) overlap if A < B+dur AND B < A+dur
            if ($currentMinutes < $otherEnd && $otherMinutes < $currentEnd) {
                $otherFormatted = sprintf('%02d:%02d', intdiv($otherMinutes, 60), $otherMinutes % 60);
                $fail("This slot overlaps with {$otherFormatted} (each slot is {$durationMinutes} min).");

                return;
            }
        }
    }

    private static function timeToMinutes($time): ?int
    {
        if (empty($time) || ! is_string($time)) {
            return null;
        }
        $parts = explode(':', $time);
        if (count($parts) < 2 || ! is_numeric($parts[0]) || ! is_numeric($parts[1])) {
            return null;
        }
        $h = (int) $parts[0];
        $m = (int) $parts[1];
        if ($h < 0 || $h > 23 || $m < 0 || $m > 59) {
            return null;
        }

        return $h * 60 + $m;
    }

    /**
     * Normalize time values: trim to HH:MM, remove nulls/empties, sort, dedupe.
     */
    private static function cleanTimeSlots(array $slots): array
    {
        return collect($slots)
            ->filter()
            ->map(fn ($t) => substr((string) $t, 0, 5)) // "06:00:00" → "06:00"
            ->unique()
            ->sort()
            ->values()
            ->toArray();
    }
}
