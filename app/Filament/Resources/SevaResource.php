<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\SevaCategory;
use App\Filament\Resources\SevaResource\Pages;
use App\Models\Seva;
use Closure;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SevaResource extends Resource
{
    protected static ?string $model = Seva::class;

    protected static ?string $navigationIcon = 'heroicon-o-hand-raised';

    protected static ?string $navigationGroup = 'Temple Management';

    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Basic Info')->schema([
                Forms\Components\TextInput::make('name_gu')->label('Name (Gujarati)')->required()->maxLength(255),
                Forms\Components\TextInput::make('name_hi')->label('Name (Hindi)')->required()->maxLength(255),
                Forms\Components\TextInput::make('name_en')->label('Name (English)')->required()->maxLength(255),
            ])->columns(3),

            Forms\Components\Section::make('Description')->schema([
                Forms\Components\RichEditor::make('description_gu')->label('Description (Gujarati)'),
                Forms\Components\RichEditor::make('description_hi')->label('Description (Hindi)'),
                Forms\Components\RichEditor::make('description_en')->label('Description (English)'),
            ]),

            Forms\Components\Section::make('Pricing & Config')->schema([
                Forms\Components\Select::make('assignee_id')
                    ->label('Seva Assignee')
                    ->relationship('assignee', 'name', fn ($query) => $query->where('is_active', true))
                    ->required()
                    ->searchable()
                    ->preload()
                    ->helperText('Admin user responsible for this seva.')
                    ->createOptionForm([
                        Forms\Components\TextInput::make('name')->required()->maxLength(255),
                        Forms\Components\TextInput::make('email')->email()->required()->unique('temple_admin_users', 'email')->maxLength(255),
                        Forms\Components\TextInput::make('password')->password()->required()->maxLength(255),
                        Forms\Components\TextInput::make('phone')->tel()->maxLength(15),
                        Forms\Components\Toggle::make('is_active')->default(true),
                    ]),
                Forms\Components\Select::make('category')->options(
                    collect(SevaCategory::cases())->mapWithKeys(fn ($c) => [$c->value => ucfirst($c->value)])
                )->required(),
                Forms\Components\TextInput::make('price')->numeric()->prefix('₹')->required(),
                Forms\Components\TextInput::make('min_price')->numeric()->prefix('₹'),
                Forms\Components\Toggle::make('is_variable_price')->label('Variable Price'),
                Forms\Components\Toggle::make('requires_booking')->label('Requires Booking')->default(true)->live(),
                Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
                Forms\Components\Toggle::make('is_active')->label('Active')->default(true),
            ])->columns(2),

            Forms\Components\Section::make('Image')->schema([
                Forms\Components\FileUpload::make('image_path')->image()->directory('sevas')->maxSize(2048),
            ]),

            Forms\Components\Section::make('Slot Configuration')
                ->icon('heroicon-o-clock')
                ->visible(fn (Get $get) => (bool) $get('requires_booking'))
                ->schema([
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
                            ->default(60),
                        Forms\Components\TextInput::make('slot_config.max_bookings_per_slot')
                            ->label('Max Bookings Per Slot')
                            ->numeric()->minValue(1)->default(1)
                            ->helperText('How many devotees can book the same time slot.'),
                    ]),

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
                                ->inline(),
                            Forms\Components\DatePicker::make('slot_config.acceptance_period.start_date')
                                ->label('Start Date')
                                ->visible(fn (Get $get) => $get('slot_config.acceptance_period.type') === 'range'),
                            Forms\Components\DatePicker::make('slot_config.acceptance_period.end_date')
                                ->label('End Date')
                                ->visible(fn (Get $get) => $get('slot_config.acceptance_period.type') === 'range')
                                ->afterOrEqual('slot_config.acceptance_period.start_date'),
                        ])->columns(3),

                    // Weekly Schedule
                    Forms\Components\Section::make('Weekly Schedule')
                        ->description('Set default time slots and override specific days if needed. Slots are validated against the duration to prevent overlaps.')
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
                                    collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'])
                                        ->flatMap(fn (string $day) => [
                                            Forms\Components\Toggle::make("customize_{$day}")
                                                ->label(ucfirst($day) . ' — custom schedule')
                                                ->inline()
                                                ->live(),
                                            Forms\Components\Repeater::make("slot_config.weekly_schedule.{$day}")
                                                ->label(ucfirst($day) . ' slots')
                                                ->simple(
                                                    Forms\Components\TimePicker::make('time')
                                                        ->seconds(false),
                                                )
                                                ->defaultItems(0)
                                                ->addActionLabel('Add Slot')
                                                ->visible(fn (Get $get) => (bool) $get("customize_{$day}"))
                                                ->helperText('No slots = closed on ' . ucfirst($day) . '.'),
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
                ])->collapsible(),

            Forms\Components\Section::make('Notification Configuration')
                ->icon('heroicon-o-bell')
                ->collapsed()
                ->schema([
                    Forms\Components\Toggle::make('notification_config.notify_on_booking')
                        ->label('Notify assignee on new booking')
                        ->default(true),
                    Forms\Components\CheckboxList::make('notification_config.booking_channels')
                        ->label('Booking notification via')
                        ->options(['whatsapp' => 'WhatsApp', 'email' => 'Email'])
                        ->default(['whatsapp'])
                        ->columns(2),

                    Forms\Components\Repeater::make('notification_config.reminders')
                        ->label('Seva Reminders')
                        ->schema([
                            Forms\Components\Select::make('timing_type')
                                ->label('When to remind')
                                ->options([
                                    'days_before' => 'Days before seva',
                                    'hours_before' => 'Hours before seva',
                                ])
                                ->required()
                                ->default('days_before')
                                ->live(),
                            Forms\Components\Select::make('timing_value')
                                ->label('Value')
                                ->options(fn (Get $get) => match ($get('timing_type')) {
                                    'days_before' => [1 => '1 day', 2 => '2 days', 3 => '3 days', 7 => '7 days'],
                                    'hours_before' => [1 => '1 hour', 2 => '2 hours', 4 => '4 hours', 6 => '6 hours', 12 => '12 hours'],
                                    default => [],
                                })
                                ->required(),
                            Forms\Components\CheckboxList::make('recipients')
                                ->label('Notify')
                                ->options(['assignee' => 'Assignee (Admin)', 'devotee' => 'Devotee'])
                                ->required()
                                ->columns(2),
                            Forms\Components\CheckboxList::make('channels')
                                ->label('Via')
                                ->options(['whatsapp' => 'WhatsApp', 'email' => 'Email'])
                                ->required()
                                ->columns(2),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('Add Reminder')
                        ->collapsible()
                        ->helperText('Configure reminders before the seva. Actual sending will be handled by a scheduled job.'),
                ]),

            Forms\Components\Section::make('Product Selection for Devotee')
                ->icon('heroicon-o-squares-2x2')
                ->collapsed()
                ->schema([
                    Forms\Components\Toggle::make('enable_product_selection')
                        ->label('Enable product selection during booking')
                        ->helperText('Devotee will see linked products as visual options during seva booking.')
                        ->live()
                        ->afterStateHydrated(function ($component, $state, $record) {
                            if ($record && !empty($record->linked_products)) {
                                $component->state(true);
                            }
                        }),
                    Forms\Components\Select::make('linked_products.type')
                        ->label('Link by')
                        ->options(['products' => 'Individual Products', 'category' => 'Entire Category'])
                        ->default('products')
                        ->live()
                        ->visible(fn (Get $get) => (bool) $get('enable_product_selection')),
                    Forms\Components\Select::make('linked_products.product_ids')
                        ->label('Select Products')
                        ->multiple()
                        ->options(fn () => \App\Models\Product::where('is_active', true)->pluck('name_en', 'id'))
                        ->searchable()
                        ->preload()
                        ->visible(fn (Get $get) => $get('enable_product_selection') && $get('linked_products.type') === 'products'),
                    Forms\Components\Select::make('linked_products.category_id')
                        ->label('Select Category')
                        ->options(fn () => \App\Models\ProductCategory::where('is_active', true)->pluck('name_en', 'id'))
                        ->searchable()
                        ->preload()
                        ->visible(fn (Get $get) => $get('enable_product_selection') && $get('linked_products.type') === 'category'),
                    Forms\Components\TextInput::make('linked_products.label_gu')
                        ->label('Selection Label (Gujarati)')
                        ->placeholder('દા.ત. વસ્ત્ર પસંદ કરો')
                        ->visible(fn (Get $get) => (bool) $get('enable_product_selection')),
                    Forms\Components\TextInput::make('linked_products.label_en')
                        ->label('Selection Label (English)')
                        ->placeholder('e.g. Choose Vastra')
                        ->visible(fn (Get $get) => (bool) $get('enable_product_selection')),
                ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\ImageColumn::make('image_path')->label('Image')->circular(),
                Tables\Columns\TextColumn::make('name_gu')->label('Name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('category')->badge()->formatStateUsing(fn ($state) => ucfirst($state->value ?? $state))->color(fn ($state) => match ($state->value ?? $state) {
                    'shringar' => 'danger',
                    'vastra' => 'info',
                    'annadan' => 'success',
                    'puja' => 'warning',
                    'special' => 'primary',
                    default => 'gray',
                }),
                Tables\Columns\TextColumn::make('price')->prefix('₹')->sortable(),
                Tables\Columns\TextColumn::make('assignee.name')->label('Assignee')->searchable()->toggleable(),
                Tables\Columns\ToggleColumn::make('is_active')->label('Active'),
                Tables\Columns\TextColumn::make('sort_order')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\SelectFilter::make('category')->options(
                    collect(SevaCategory::cases())->mapWithKeys(fn ($c) => [$c->value => ucfirst($c->value)])
                ),
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\DeleteBulkAction::make(),
                ]),
            ]);
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSevas::route('/'),
            'create' => Pages\CreateSeva::route('/create'),
            'edit' => Pages\EditSeva::route('/{record}/edit'),
        ];
    }
}
