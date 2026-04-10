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
            Forms\Components\Section::make('Basic Info')->schema([
                Forms\Components\TextInput::make('name')
                    ->required()
                    ->maxLength(255),
                Forms\Components\TextInput::make('capacity')
                    ->numeric()
                    ->required(),
                Forms\Components\RichEditor::make('description')
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('rules')
                    ->rows(4)
                    ->columnSpanFull(),
            ])->columns(2),

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
                Tables\Columns\TextColumn::make('name')
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
