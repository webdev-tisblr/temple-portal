<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\HallResource\Pages;
use App\Models\Hall;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

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
                \App\Filament\Support\TranslatableTabs::make(fn (string $locale, string $label) => [
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

            // Bookings are full-day only (2026-08-04): single price.
            Forms\Components\Section::make('Pricing')->schema([
                Forms\Components\TextInput::make('price_per_day')
                    ->label('Price (per day)')
                    ->numeric()
                    ->prefix('₹')
                    ->required()
                    ->helperText('Hall bookings are full-day only — one price per date.'),
            ]),

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
                                ->visible(fn (\Filament\Forms\Get $get): bool => $get('media_type') === 'photo'),
                            Forms\Components\TextInput::make('video_url')
                                ->label('Video URL')
                                ->url()
                                ->maxLength(500)
                                ->placeholder('https://youtu.be/xxxxxxxxxxx')
                                ->visible(fn (\Filament\Forms\Get $get): bool => $get('media_type') === 'video'),
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
                            ? \Illuminate\Support\Carbon::parse($state['date'])->format('d/m/Y').' — '.($state['reason'] ?? '')
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
