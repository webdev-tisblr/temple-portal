<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DevoteeResource\Pages;
use App\Models\Devotee;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DevoteeResource extends Resource
{
    protected static ?string $model = Devotee::class;

    protected static ?string $navigationIcon = 'heroicon-o-users';

    protected static ?string $navigationGroup = 'Temple Management';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Personal Info')->schema([
                Forms\Components\TextInput::make('name')->required()->maxLength(255),
                Forms\Components\TextInput::make('phone')->tel()->required()->maxLength(15)
                    ->disabled(fn (?Devotee $record) => $record !== null),
                Forms\Components\TextInput::make('email')->email()->maxLength(255),
                Forms\Components\DatePicker::make('date_of_birth'),
                Forms\Components\Select::make('language')->options(['gu' => 'Gujarati', 'hi' => 'Hindi', 'en' => 'English'])->default('gu'),
                Forms\Components\Toggle::make('is_active')->default(true),
            ])->columns(2),

            Forms\Components\Section::make('Address')->schema([
                Forms\Components\Textarea::make('address')->rows(2),
                Forms\Components\TextInput::make('city')->maxLength(100),
                Forms\Components\TextInput::make('state')->default('Gujarat')->maxLength(100),
                Forms\Components\TextInput::make('pincode')->maxLength(10),
            ])->columns(2),

            Forms\Components\Section::make('PAN Info')->schema([
                Forms\Components\Placeholder::make('pan_status')
                    ->label('PAN on file')
                    ->content(fn (?Devotee $record) => $record?->pan_encrypted ? 'Yes (last 4: ' . $record->pan_last_four . ')' : 'No'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->limit(8)->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('phone')->searchable(),
                Tables\Columns\TextColumn::make('city')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('language')->badge(),
                Tables\Columns\TextColumn::make('last_login_at')->dateTime('d M Y')->sortable()->label('Last Login'),
                Tables\Columns\TextColumn::make('created_at')->dateTime('d M Y')->sortable()->label('Registered'),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('language')->options(['gu' => 'Gujarati', 'hi' => 'Hindi', 'en' => 'English']),
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
            'index' => Pages\ListDevotees::route('/'),
            'create' => Pages\CreateDevotee::route('/create'),
            'edit' => Pages\EditDevotee::route('/{record}/edit'),
        ];
    }
}
