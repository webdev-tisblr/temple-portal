<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\TrusteeResource\Pages;
use App\Models\Trustee;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class TrusteeResource extends Resource
{
    protected static ?string $model = Trustee::class;

    protected static ?string $navigationIcon = 'heroicon-o-user-group';

    protected static ?string $navigationGroup = 'One-Time Setup';

    protected static ?int $navigationSort = 10;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Content')->schema([
                \App\Filament\Support\TranslatableTabs::make(fn (string $locale, string $label) => [
                    Forms\Components\TextInput::make("name_{$locale}")->label("Name {$label}")->required($locale === 'gu')->maxLength(255),
                    // Designation is uniform by trust decision: every trustee is
                    // "Trustee" (ટ્રસ્ટી / ट्रस्टी) — forced in Trustee::booted(),
                    // so there is no role input here.
                    Forms\Components\TextInput::make("location_{$locale}")->label("Location {$label}")->maxLength(255)
                        ->placeholder($locale === 'gu' ? 'ગાંધીધામ, કચ્છ' : null),
                ]),
            ]),

            Forms\Components\Section::make('Details')->schema([
                Forms\Components\FileUpload::make('photo_path')->label('Photo')->image()->directory('trustees')->maxSize(4096)->imageEditor(),
                Forms\Components\TextInput::make('sort_order')->numeric()->default(0)->helperText('Lower shows first.'),
                Forms\Components\Toggle::make('is_active')->label('Active')->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('photo_path')->label('Photo')->circular(),
                Tables\Columns\TextColumn::make('name_gu')->label('Name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('role_gu')->label('Role'),
                Tables\Columns\TextColumn::make('location')->label('Location')->toggleable(),
                Tables\Columns\TextColumn::make('sort_order')->label('Order')->sortable(),
                Tables\Columns\IconColumn::make('is_active')->boolean()->label('Active'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->filters([
                Tables\Filters\TernaryFilter::make('is_active'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListTrustees::route('/'),
            'create' => Pages\CreateTrustee::route('/create'),
            'edit' => Pages\EditTrustee::route('/{record}/edit'),
        ];
    }
}
