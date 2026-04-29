<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DailyDarshanPhotoResource\Pages;
use App\Models\DailyDarshanPhoto;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class DailyDarshanPhotoResource extends Resource
{
    protected static ?string $model = DailyDarshanPhoto::class;
    protected static ?string $navigationIcon = 'heroicon-o-camera';
    protected static ?string $navigationGroup = 'Content';
    protected static ?int $navigationSort = 4;
    protected static ?string $navigationLabel = 'Daily Darshan Photo';
    protected static ?string $modelLabel = 'Daily Darshan Photo';
    protected static ?string $pluralModelLabel = 'Daily Darshan Photos';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Photo')->schema([
                Forms\Components\FileUpload::make('image_path')
                    ->image()
                    ->directory('daily-darshan')
                    ->required()
                    ->imageEditor(),
                Forms\Components\DatePicker::make('captured_on')
                    ->default(now())
                    ->required(),
                Forms\Components\Toggle::make('is_active')
                    ->label('Active (visible in app)')
                    ->default(true),
            ])->columns(2),
            Forms\Components\Section::make('Caption (optional)')->schema([
                Forms\Components\TextInput::make('caption_gu')->label('Gujarati')->maxLength(500),
                Forms\Components\TextInput::make('caption_hi')->label('Hindi')->maxLength(500),
                Forms\Components\TextInput::make('caption_en')->label('English')->maxLength(500),
            ])->columns(3)->collapsed(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')->disk('public')->square()->size(60),
                Tables\Columns\TextColumn::make('captured_on')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('caption_gu')->label('Caption')->limit(40),
                Tables\Columns\ToggleColumn::make('is_active'),
                Tables\Columns\TextColumn::make('created_at')->dateTime('d M Y H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('captured_on', 'desc')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
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
            'index' => Pages\ListDailyDarshanPhotos::route('/'),
            'create' => Pages\CreateDailyDarshanPhoto::route('/create'),
            'edit' => Pages\EditDailyDarshanPhoto::route('/{record}/edit'),
        ];
    }
}
