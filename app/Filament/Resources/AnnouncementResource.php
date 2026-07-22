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
    protected static ?string $navigationIcon = 'heroicon-o-megaphone';
    protected static ?string $navigationGroup = 'Content Management';
    protected static ?int $navigationSort = 60;
    protected static ?string $navigationLabel = 'Announcements';
    protected static ?string $modelLabel = 'Mandir News';
    protected static ?string $pluralModelLabel = 'Mandir News';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Content')->schema([
                \App\Filament\Support\TranslatableTabs::make(fn (string $locale, string $label) => [
                    Forms\Components\TextInput::make("title_{$locale}")->label("Title {$label}")->required($locale === 'gu')->maxLength(500),
                    Forms\Components\RichEditor::make("body_{$locale}")->label("Body {$label}")->required($locale === 'gu'),
                ]),
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
