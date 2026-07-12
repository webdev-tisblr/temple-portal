<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\HeroSlideResource\Pages;
use App\Filament\Support\TranslatableTabs;
use App\Models\HeroSlide;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class HeroSlideResource extends Resource
{
    protected static ?string $model = HeroSlide::class;

    protected static ?string $navigationIcon = 'heroicon-o-photo';
    protected static ?string $navigationGroup = 'Content Management';
    protected static ?string $navigationLabel = 'Hero Slider';
    protected static ?string $modelLabel = 'Hero Slide';
    protected static ?int $navigationSort = 1;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Slide Image')->schema([
                Forms\Components\FileUpload::make('image_path')
                    ->label('Background image (desktop)')
                    ->image()->directory('hero-slides')->maxSize(4096)->required()
                    ->helperText('Recommended 1920×900 or wider.'),
                Forms\Components\FileUpload::make('image_path_mobile')
                    ->label('Background image (mobile, optional)')
                    ->image()->directory('hero-slides')->maxSize(2048)
                    ->helperText('Portrait crop for phones; desktop image is used when empty.'),
            ])->columns(2),

            Forms\Components\Section::make('Text & Button')->schema([
                TranslatableTabs::make(fn (string $locale, string $label) => [
                    Forms\Components\TextInput::make("heading_{$locale}")->label("Heading {$label}")->maxLength(500),
                    Forms\Components\Textarea::make("sub_{$locale}")->label("Subtext {$label}")->rows(2),
                    Forms\Components\TextInput::make("cta_label_{$locale}")->label("Button label {$label}")->maxLength(200),
                ]),
                Forms\Components\TextInput::make('cta_url')
                    ->label('Button link')
                    ->placeholder('/donate or https://…')
                    ->maxLength(500),
            ]),

            Forms\Components\Section::make('Appearance & Behaviour')->schema([
                Forms\Components\Select::make('align')->label('Text alignment')
                    ->options(['left' => 'Left', 'center' => 'Center', 'right' => 'Right'])
                    ->default('center')->required(),
                Forms\Components\Select::make('theme')->label('Text colour')
                    ->options(['dark' => 'Dark text (light photos)', 'light' => 'Light text (dark photos)'])
                    ->default('dark')->required(),
                Forms\Components\TextInput::make('overlay_opacity')
                    ->label('Background veil strength (%)')
                    ->numeric()->minValue(0)->maxValue(90)->default(40)
                    ->helperText('Higher = more readable text, more muted photo.'),
                Forms\Components\Select::make('transition')->label('Transition')
                    ->options(['fade' => 'Fade', 'slide' => 'Slide', 'zoom' => 'Zoom (Ken Burns)'])
                    ->default('fade')->required(),
                Forms\Components\TextInput::make('duration_seconds')
                    ->label('Show for (seconds)')
                    ->numeric()->minValue(3)->maxValue(30)->default(6),
                Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
            ])->columns(3),

            Forms\Components\Section::make('Schedule')->schema([
                Forms\Components\DateTimePicker::make('starts_at')
                    ->label('Show from')->native(false)->displayFormat('d M Y h:i A')->seconds(false),
                Forms\Components\DateTimePicker::make('ends_at')
                    ->label('Show until')->native(false)->displayFormat('d M Y h:i A')->seconds(false),
                Forms\Components\Toggle::make('is_active')->label('Active')->default(true),
            ])->columns(3)->description('Leave dates empty to show always (while Active).'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')->label('Image')->height(46),
                Tables\Columns\TextColumn::make('heading_gu')->label('Heading')->limit(35),
                Tables\Columns\TextColumn::make('transition')->badge(),
                Tables\Columns\TextColumn::make('starts_at')->label('From')->dateTime('d M h:i A')->placeholder('—'),
                Tables\Columns\TextColumn::make('ends_at')->label('Until')->dateTime('d M h:i A')->placeholder('—'),
                Tables\Columns\ToggleColumn::make('is_active')->label('Active'),
            ])
            ->reorderable('sort_order')
            ->defaultSort('sort_order')
            ->actions([
                Tables\Actions\EditAction::make(),
                Tables\Actions\DeleteAction::make(),
            ])
            ->bulkActions([Tables\Actions\DeleteBulkAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHeroSlides::route('/'),
            'create' => Pages\CreateHeroSlide::route('/create'),
            'edit' => Pages\EditHeroSlide::route('/{record}/edit'),
        ];
    }
}
