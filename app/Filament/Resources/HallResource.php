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
    protected static ?string $navigationGroup = 'Temple Management';
    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Name')->schema([
                Forms\Components\TextInput::make('name_gu')->label('Name (Gujarati)')->required()->maxLength(255),
                Forms\Components\TextInput::make('name_hi')->label('Name (Hindi)')->maxLength(255),
                Forms\Components\TextInput::make('name_en')->label('Name (English)')->maxLength(255),
            ])->columns(3),

            Forms\Components\Section::make('Capacity')->schema([
                Forms\Components\TextInput::make('capacity')
                    ->numeric()
                    ->required(),
            ]),

            Forms\Components\Section::make('Description')->schema([
                Forms\Components\RichEditor::make('description_gu')->label('Description (Gujarati)'),
                Forms\Components\RichEditor::make('description_hi')->label('Description (Hindi)'),
                Forms\Components\RichEditor::make('description_en')->label('Description (English)'),
            ]),

            Forms\Components\Section::make('Rules')->schema([
                Forms\Components\Textarea::make('rules_gu')->label('Rules (Gujarati)')->rows(4),
                Forms\Components\Textarea::make('rules_hi')->label('Rules (Hindi)')->rows(4),
                Forms\Components\Textarea::make('rules_en')->label('Rules (English)')->rows(4),
            ])->columns(1),

            Forms\Components\Section::make('Pricing')->schema([
                Forms\Components\TextInput::make('price_per_day')
                    ->numeric()
                    ->prefix('₹')
                    ->required(),
                Forms\Components\TextInput::make('price_per_half_day')
                    ->numeric()
                    ->prefix('₹'),
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
