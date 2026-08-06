<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\GuideResource\Pages;
use App\Models\Guide;
use App\Models\GuideCategory;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * User Guides / Help Center — admin-authored "how to use the app"
 * articles served to the Flutter app via GET /api/v1/guides.
 *
 * Content model: localized title + short summary + rich-text body, an
 * optional cover image, and a photo/video media strip (same child-table
 * shape as Hall/Seva/Event media). Future content types extend the
 * media_type enum or add nullable columns — no schema upheaval.
 */
class GuideResource extends Resource
{
    protected static ?string $model = Guide::class;

    protected static ?string $navigationIcon = 'heroicon-o-question-mark-circle';

    protected static ?string $navigationGroup = 'Content Management';

    protected static ?int $navigationSort = 55;

    protected static ?string $navigationLabel = 'User Guides';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Guide')->schema([
                Forms\Components\Select::make('category_id')
                    ->label('Category')
                    ->options(fn () => GuideCategory::where('is_active', true)
                        ->orderBy('sort_order')
                        // name is a localized accessor — load models
                        // before plucking (raw pluck reads no column).
                        ->get()
                        ->pluck('name', 'id'))
                    ->searchable()
                    ->placeholder('No category')
                    ->helperText('Optional — uncategorised guides still show in the app.'),
                Forms\Components\Toggle::make('is_active')
                    ->default(true)
                    ->helperText('Hidden from the app when off.'),
            ])->columns(2),

            Forms\Components\Section::make('Content')->schema([
                \App\Filament\Support\TranslatableTabs::make(fn (string $locale, string $label) => [
                    Forms\Components\TextInput::make("title_{$locale}")
                        ->label("Title {$label}")
                        ->required($locale === 'gu')
                        ->maxLength(255),
                    Forms\Components\Textarea::make("summary_{$locale}")
                        ->label("Summary {$label}")
                        ->rows(2)
                        ->maxLength(500)
                        ->helperText('Short line shown on the guide card in the app.'),
                    Forms\Components\RichEditor::make("body_{$locale}")
                        ->label("Body {$label}")
                        ->helperText('The full guide. Images/videos go in the media section below, not inline.'),
                ]),
            ]),

            Forms\Components\Section::make('Cover Image')->schema([
                Forms\Components\FileUpload::make('cover_image')
                    ->directory('guides')
                    ->image()
                    ->maxSize(2048)
                    ->helperText('Shown on the guide card and at the top of the guide.'),
            ]),

            Forms\Components\Section::make('Photos & Videos')
                ->description('Step screenshots and video walkthroughs. Photos are uploaded; videos are YouTube / hosted links.')
                ->schema([
                    Forms\Components\Repeater::make('media')
                        ->relationship()
                        ->schema([
                            Forms\Components\Select::make('media_type')
                                ->options(['photo' => 'Photo', 'video' => 'Video'])
                                ->default('photo')
                                ->live()
                                ->required(),
                            Forms\Components\FileUpload::make('image_path')
                                ->label('Photo')
                                ->image()
                                ->directory('guides/media')
                                ->maxSize(4096)
                                ->visible(fn (\Filament\Forms\Get $get): bool => $get('media_type') === 'photo'),
                            Forms\Components\TextInput::make('video_url')
                                ->label('Video URL')
                                ->url()
                                ->maxLength(500)
                                ->placeholder('https://youtu.be/xxxxxxxxxxx')
                                ->visible(fn (\Filament\Forms\Get $get): bool => $get('media_type') === 'video'),
                            Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
                        ])
                        ->columns(2)
                        ->defaultItems(0)
                        ->reorderable()
                        ->orderColumn('sort_order')
                        ->addActionLabel('Add Photo / Video'),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('cover_image')->label('Cover')->square(),
                Tables\Columns\TextColumn::make('title_gu')->label('Title')->searchable(),
                Tables\Columns\TextColumn::make('category.name_gu')->label('Category')->placeholder('—'),
                Tables\Columns\ToggleColumn::make('is_active')->label('Active'),
                Tables\Columns\TextColumn::make('updated_at')->dateTime('d/m/Y H:i')->label('Updated'),
            ])
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
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
            'index' => Pages\ListGuides::route('/'),
            'create' => Pages\CreateGuide::route('/create'),
            'edit' => Pages\EditGuide::route('/{record}/edit'),
        ];
    }
}
