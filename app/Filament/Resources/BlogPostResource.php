<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\BlogPostResource\Pages;
use App\Models\BlogPost;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class BlogPostResource extends Resource
{

    protected static ?string $model = BlogPost::class;
    protected static ?string $navigationIcon = 'heroicon-o-pencil-square';
    protected static ?string $navigationGroup = 'Content Management';
    protected static ?int $navigationSort = 40;
    protected static ?string $navigationLabel = 'News / Samachar';

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Content')->schema([
                \App\Filament\Support\TranslatableTabs::make(fn (string $locale, string $label) => [
                    Forms\Components\TextInput::make("title_{$locale}")->label("Title {$label}")->required($locale === 'gu')->maxLength(500)
                        ->live(onBlur: $locale === 'gu')
                        ->afterStateUpdated($locale === 'gu'
                            ? fn (Forms\Set $set, ?string $state) => $set('slug', Str::slug($state ?? ''))
                            : null),
                    Forms\Components\RichEditor::make("body_{$locale}")->label("Content {$label}")->required($locale === 'gu'),
                    Forms\Components\Textarea::make("excerpt_{$locale}")->label("Excerpt {$label}")->rows(2),
                ]),
                Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(255),
            ]),
            Forms\Components\Section::make('Settings')->schema([
                Forms\Components\FileUpload::make('featured_image_path')->image()->directory('blog'),
                Forms\Components\TextInput::make('category')->default('general'),
                Forms\Components\Select::make('status')->options(['draft' => 'Draft', 'published' => 'Published'])->default('draft'),
                Forms\Components\DateTimePicker::make('published_at'),
                Forms\Components\TextInput::make('meta_title')->maxLength(255),
                Forms\Components\Textarea::make('meta_description')->rows(2),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title_gu')->label('Title')->searchable()->sortable()->limit(50),
                Tables\Columns\TextColumn::make('slug')->searchable(),
                Tables\Columns\TextColumn::make('category')->badge(),
                Tables\Columns\TextColumn::make('status')->badge()->color(function ($state) {
                    // status column is cast to PageStatus enum on the
                    // model, so $state arrives as the enum instance.
                    // Normalise to the backing string for the comparison.
                    $value = $state instanceof \BackedEnum ? $state->value : (string) $state;
                    return $value === 'published' ? 'success' : 'warning';
                }),
                Tables\Columns\TextColumn::make('published_at')->dateTime('d M Y'),
            ])
            ->defaultSort('created_at', 'desc')
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListBlogPosts::route('/'),
            'create' => Pages\CreateBlogPost::route('/create'),
            'edit' => Pages\EditBlogPost::route('/{record}/edit'),
        ];
    }
}
