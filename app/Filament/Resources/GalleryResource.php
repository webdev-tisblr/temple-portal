<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\GalleryResource\Pages;
use App\Models\GalleryImage;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class GalleryResource extends Resource
{
    protected static ?string $model = GalleryImage::class;
    protected static ?string $navigationIcon = 'heroicon-o-photo';
    protected static ?string $navigationGroup = 'Content';
    protected static ?string $navigationLabel = 'Gallery';
    protected static ?int $navigationSort = 3;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make()->schema([
                Forms\Components\TextInput::make('title')->maxLength(255),
                Forms\Components\Textarea::make('description')->maxLength(500)->rows(2),
                Forms\Components\FileUpload::make('image_path')->image()->required()->directory('gallery')->maxSize(5120),
                Forms\Components\Select::make('category')->options([
                    'temple' => 'Temple', 'deity' => 'Deity', 'festival' => 'Festival',
                    'event' => 'Event', 'wallpaper' => 'Wallpaper', 'other' => 'Other',
                ])->default('temple')->required(),
                Forms\Components\Toggle::make('is_wallpaper')->label('Available as Wallpaper'),
                Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')->label('Image')->square()->size(60),
                Tables\Columns\TextColumn::make('title')->searchable()->limit(30),
                Tables\Columns\TextColumn::make('category')->badge(),
                Tables\Columns\IconColumn::make('is_wallpaper')->boolean()->label('Wallpaper'),
                Tables\Columns\TextColumn::make('sort_order')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\SelectFilter::make('category')->options([
                    'temple' => 'Temple', 'deity' => 'Deity', 'festival' => 'Festival',
                    'event' => 'Event', 'wallpaper' => 'Wallpaper', 'other' => 'Other',
                ]),
                Tables\Filters\TernaryFilter::make('is_wallpaper'),
            ])
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListGalleryImages::route('/'),
            'create' => Pages\CreateGalleryImage::route('/create'),
            'edit' => Pages\EditGalleryImage::route('/{record}/edit'),
        ];
    }
}
