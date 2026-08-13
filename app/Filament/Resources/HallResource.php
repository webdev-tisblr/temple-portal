<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\HallResource\Pages;
use App\Filament\Support\ReminderRuleFields;
use App\Filament\Support\TranslatableTabs;
use App\Models\Hall;
use App\Services\HallReminderScheduler;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Get;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class HallResource extends Resource
{
    protected static ?string $model = Hall::class;

    protected static ?string $navigationIcon = 'heroicon-o-building-office-2';

    protected static ?string $navigationGroup = 'Portal Setup';

    protected static ?int $navigationSort = 50;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Content')->schema([
                TranslatableTabs::make(fn (string $locale, string $label) => [
                    Forms\Components\TextInput::make("name_{$locale}")->label("Name {$label}")->required($locale === 'gu')->maxLength(255),
                    Forms\Components\RichEditor::make("description_{$locale}")->label("Description {$label}"),
                    Forms\Components\Textarea::make("rules_{$locale}")->label("Rules {$label}")->rows(4),
                ]),
            ]),

            Forms\Components\Section::make('Capacity')->schema([
                Forms\Components\TextInput::make('capacity')
                    ->numeric()
                    ->required(),
            ]),

            Forms\Components\Section::make('Booking Rules')
                ->description('Multi-day bookings and the booking cut-off. Defaults keep the old behaviour exactly: one day at a time, no cut-off.')
                ->schema([
                    Forms\Components\TextInput::make('max_booking_days')
                        ->label('Maximum days per booking')
                        ->numeric()->minValue(1)->maxValue(90)->default(1)
                        ->required()
                        ->helperText('1 = single-day bookings only. Set higher to let devotees book a consecutive range (e.g. 3 = a 12th–14th booking, charged 3 × the day rate, with all three days blocked).'),
                    Forms\Components\TextInput::make('booking_cutoff_hours')
                        ->label('Booking cut-off (hours)')
                        ->numeric()->minValue(0)->maxValue(8760)->default(0)
                        ->suffix('hours')
                        ->helperText('Devotees cannot book a date starting within the next N hours. Set 0 for no cut-off. Counted back from the day-start time below.'),
                    Forms\Components\TimePicker::make('day_start_time')
                        ->label('Day starts at')
                        ->native(false)
                        ->seconds(false)
                        ->format('H:i')
                        ->displayFormat('h:i A')
                        ->default('09:00')
                        ->helperText('A hall booking has no start time, so this is the moment the cut-off counts back from.'),
                ])->columns(3),

            Forms\Components\Section::make('Reminders')
                ->icon('heroicon-o-bell')
                ->description('Add any number of rules — each one is when + who + channel + message. Counted back from the day-start time on the FIRST booked day, and applied to bookings confirmed after the rule exists. Nothing sends until a template is enabled for the "Hall — reminder before booking" trigger.')
                ->collapsed()
                ->schema([
                    Forms\Components\Repeater::make('reminderRules')
                        ->relationship()
                        // visible(), never disabled() — Filament skips hidden
                        // components when dehydrating AND when running
                        // saveRelationships, so an admin without the
                        // permission cannot add, edit or silently WIPE rules
                        // by saving the rest of the hall form. Same reasoning
                        // as the seva repeater (G7, 2026-08-09).
                        ->visible(fn (): bool => auth('admin')->user()?->can('update_hall::reminder::rule') ?? false)
                        ->hiddenLabel()
                        ->schema(ReminderRuleFields::schema(
                            subject: 'booking',
                            triggerKey: 'hall.booking.reminder',
                            withAssignee: false,
                            placeholders: '{{contact_name}} {{devotee_name}} {{hall_name}} {{booking_date}} {{booking_date_range}} {{days_count}} {{amount}} {{booking_number}} {{time_remaining_label}} {{trust_name}} {{admin_name}}',
                        ))
                        ->columns(2)
                        ->defaultItems(0)
                        ->addActionLabel('Add reminder rule')
                        ->collapsible()
                        ->itemLabel(fn (array $state): ?string => isset($state['offset_minutes'])
                            ? HallReminderScheduler::humanLabel((int) $state['offset_minutes'])
                                .' before → '.($state['recipient_type'] ?? '').' → '.($state['channel'] ?? '')
                            : null),
                ]),

            // Bookings are full-day only (2026-08-04): single price.
            Forms\Components\Section::make('Pricing')->schema([
                Forms\Components\TextInput::make('price_per_day')
                    ->label('Price (per day)')
                    ->numeric()
                    ->prefix('₹')
                    ->required()
                    ->helperText('Hall bookings are full-day only — one price per date. GST, when enabled, is INCLUDED in this price, not added at checkout — the devotee pays exactly this.'),
                Forms\Components\TextInput::make('gst_rate')
                    ->label('GST rate override (%)')
                    ->numeric()
                    ->minValue(0)
                    ->maxValue(28)
                    ->step(0.01)
                    ->suffix('%')
                    ->helperText('Leave blank to use the trust-wide default from System Settings → Hall GST. Set a value only when THIS hall is taxed differently. Has no effect while hall GST is switched off.'),
            ])->columns(2),

            Forms\Components\Section::make('Image')->schema([
                Forms\Components\FileUpload::make('image_path')
                    ->directory('halls')
                    ->image()
                    ->maxSize(2048),
            ]),

            Forms\Components\Section::make('Photos & Videos')
                ->description('Gallery of photos and videos for this hall. Photos are uploaded; videos are YouTube / hosted links.')
                ->schema([
                    Forms\Components\Repeater::make('media')
                        ->relationship()
                        ->schema([
                            Forms\Components\Select::make('media_type')
                                ->options(['photo' => 'Photo', 'video' => 'Video'])
                                ->default('photo')
                                ->live()
                                ->required(),
                            Forms\Components\FileUpload::make('image_path')
                                ->label('Photo')
                                ->image()
                                ->directory('hall-media')
                                ->maxSize(4096)
                                ->visible(fn (Get $get): bool => $get('media_type') === 'photo'),
                            Forms\Components\TextInput::make('video_url')
                                ->label('Video URL')
                                ->url()
                                ->maxLength(500)
                                ->placeholder('https://youtu.be/xxxxxxxxxxx')
                                ->visible(fn (Get $get): bool => $get('media_type') === 'video'),
                            Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->reorderable()
                        ->orderColumn('sort_order')
                        ->addActionLabel('Add Photo / Video'),
                ]),

            Forms\Components\Section::make('Amenities')->schema([
                Forms\Components\TagsInput::make('amenities')
                    ->placeholder('e.g. AC, Sound System'),
            ]),

            Forms\Components\Section::make('Availability — Blockout Dates')
                ->icon('heroicon-o-no-symbol')
                ->description('Dates listed here cannot be booked (web + app), regardless of existing bookings — for maintenance, trust events, festivals, etc.')
                ->collapsed()
                ->schema([
                    Forms\Components\CheckboxList::make('blackout_days')
                        ->label('Block every week on these days')
                        ->options([
                            'monday' => 'Monday',
                            'tuesday' => 'Tuesday',
                            'wednesday' => 'Wednesday',
                            'thursday' => 'Thursday',
                            'friday' => 'Friday',
                            'saturday' => 'Saturday',
                            'sunday' => 'Sunday',
                        ])
                        ->columns(['default' => 2, 'sm' => 4, 'lg' => 7])
                        ->gridDirection('row')
                        ->bulkToggleable()
                        ->helperText('Recurring weekly closure — e.g. tick Monday and NO Monday is ever bookable. Leave all unchecked for no weekly restriction. One-off dates go in the list below.'),
                    Forms\Components\Repeater::make('blackout_dates')
                        ->hiddenLabel()
                        ->schema([
                            Forms\Components\DatePicker::make('date')
                                ->label('Date')
                                ->required()
                                ->native(false)
                                ->displayFormat('d/m/Y')
                                ->minDate(now()->startOfDay()),
                            Forms\Components\TextInput::make('reason')
                                ->label('Reason (shown to devotees)')
                                ->required()
                                ->maxLength(255)
                                ->placeholder('e.g. ટ્રસ્ટ કાર્યક્રમ માટે બુક'),
                        ])
                        ->columns(2)
                        ->collapsed()
                        ->defaultItems(0)
                        ->addActionLabel('Add Blockout Date')
                        ->itemLabel(fn (array $state): ?string => ($state['date'] ?? null)
                            ? Carbon::parse($state['date'])->format('d/m/Y').' — '.($state['reason'] ?? '')
                            : null),
                ]),

            Forms\Components\Toggle::make('is_active')
                ->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('name_gu')
                    ->label('Name')
                    ->searchable(),
                Tables\Columns\TextColumn::make('capacity'),
                Tables\Columns\TextColumn::make('max_booking_days')
                    ->label('Max days')
                    ->formatStateUsing(fn ($state): string => ((int) $state) > 1 ? (string) $state : 'Single day')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('booking_cutoff_hours')
                    ->label('Cut-off')
                    ->formatStateUsing(fn ($state): string => ((int) $state) > 0 ? $state.' h' : '—')
                    ->toggleable(),
                Tables\Columns\TextColumn::make('price_per_day')
                    ->prefix('₹'),
                Tables\Columns\ToggleColumn::make('is_active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHalls::route('/'),
            'create' => Pages\CreateHall::route('/create'),
            'edit' => Pages\EditHall::route('/{record}/edit'),
        ];
    }
}
