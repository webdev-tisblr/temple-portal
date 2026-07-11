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

    protected static ?string $navigationGroup = 'Content';

    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Name')->schema([
                Forms\Components\TextInput::make('name_gu')->label('Name (Gujarati)')->required()->maxLength(255),
                Forms\Components\TextInput::make('name_hi')->label('Name (Hindi)')->maxLength(255),
                Forms\Components\TextInput::make('name_en')->label('Name (English)')->maxLength(255),
            ])->columns(3),

            Forms\Components\Section::make('Role / Designation')->schema([
                Forms\Components\TextInput::make('role_gu')->label('Role (Gujarati)')->maxLength(255)->placeholder('પ્રમુખ (Chairman)'),
                Forms\Components\TextInput::make('role_hi')->label('Role (Hindi)')->maxLength(255),
                Forms\Components\TextInput::make('role_en')->label('Role (English)')->maxLength(255),
            ])->columns(3),

            Forms\Components\Section::make('Details')->schema([
                Forms\Components\TextInput::make('location_gu')->label('Location (Gujarati)')->maxLength(255)->placeholder('ગાંધીધામ, કચ્છ'),
                Forms\Components\TextInput::make('location_hi')->label('Location (Hindi)')->maxLength(255),
                Forms\Components\TextInput::make('location_en')->label('Location (English)')->maxLength(255),
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
                Tables\Columns\TextColumn::make('role_gu')->label('Role')->searchable(),
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
