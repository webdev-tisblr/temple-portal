<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\SevaCategory;
use App\Filament\Resources\SevaResource\Pages;
use App\Models\Seva;
use Filament\Forms;
use Filament\Forms\Form;
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
                Forms\Components\Select::make('category')->options(
                    collect(SevaCategory::cases())->mapWithKeys(fn ($c) => [$c->value => ucfirst($c->value)])
                )->required(),
                Forms\Components\TextInput::make('price')->numeric()->prefix('₹')->required(),
                Forms\Components\TextInput::make('min_price')->numeric()->prefix('₹'),
                Forms\Components\Toggle::make('is_variable_price')->label('Variable Price'),
                Forms\Components\Toggle::make('requires_booking')->label('Requires Booking')->default(true),
                Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
                Forms\Components\Toggle::make('is_active')->label('Active')->default(true),
            ])->columns(2),

            Forms\Components\Section::make('Image')->schema([
                Forms\Components\FileUpload::make('image_path')->image()->directory('sevas')->maxSize(2048),
            ]),

            Forms\Components\Section::make('Slot Config (JSON)')->schema([
                Forms\Components\KeyValue::make('slot_config')->label('Slot Configuration'),
            ])->collapsed(),
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

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSevas::route('/'),
            'create' => Pages\CreateSeva::route('/create'),
            'edit' => Pages\EditSeva::route('/{record}/edit'),
        ];
    }
}
