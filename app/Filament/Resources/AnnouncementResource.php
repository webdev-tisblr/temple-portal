<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\AnnouncementResource\Pages;
use App\Models\Announcement;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class AnnouncementResource extends Resource
{
    protected static ?string $model = Announcement::class;
    protected static ?string $navigationIcon = 'heroicon-o-speaker-wave';
    protected static ?string $navigationGroup = 'Content';
    protected static ?int $navigationSort = 5;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Title')->schema([
                Forms\Components\TextInput::make('title_gu')->label('Title (Gujarati)')->required()->maxLength(500),
                Forms\Components\TextInput::make('title_hi')->label('Title (Hindi)')->maxLength(500),
                Forms\Components\TextInput::make('title_en')->label('Title (English)')->maxLength(500),
            ])->columns(3),
            Forms\Components\Section::make('Body')->schema([
                Forms\Components\RichEditor::make('body_gu')->label('Body (Gujarati)')->required(),
                Forms\Components\RichEditor::make('body_hi')->label('Body (Hindi)'),
                Forms\Components\RichEditor::make('body_en')->label('Body (English)'),
            ]),
            Forms\Components\Section::make('Settings')->schema([
                Forms\Components\FileUpload::make('image_path')->image()->directory('announcements'),
                Forms\Components\Toggle::make('is_urgent')->label('Urgent'),
                Forms\Components\DateTimePicker::make('published_at'),
                Forms\Components\DateTimePicker::make('expires_at'),
                Forms\Components\Toggle::make('is_active')->default(true),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title_gu')->label('Title')->searchable()->limit(50),
                Tables\Columns\IconColumn::make('is_urgent')->boolean()->label('Urgent'),
                Tables\Columns\TextColumn::make('published_at')->dateTime('d M Y H:i')->sortable(),
                Tables\Columns\TextColumn::make('expires_at')->dateTime('d M Y H:i'),
                Tables\Columns\ToggleColumn::make('is_active'),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListAnnouncements::route('/'),
            'create' => Pages\CreateAnnouncement::route('/create'),
            'edit' => Pages\EditAnnouncement::route('/{record}/edit'),
        ];
    }
}
