<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\PageResource\Pages;
use App\Models\Page;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class PageResource extends Resource
{
    protected static ?string $model = Page::class;
    protected static ?string $navigationIcon = 'heroicon-o-document-duplicate';
    protected static ?string $navigationGroup = 'Content';
    protected static ?string $navigationLabel = 'CMS Pages';
    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Page Info')->schema([
                Forms\Components\TextInput::make('title_gu')->label('Title (Gujarati)')->required()->maxLength(500)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn (Forms\Set $set, ?string $state) => $set('slug', Str::slug($state ?? ''))),
                Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(255),
                Forms\Components\TextInput::make('title_hi')->label('Title (Hindi)')->maxLength(500),
                Forms\Components\TextInput::make('title_en')->label('Title (English)')->maxLength(500),
            ])->columns(2),

            Forms\Components\Section::make('Content')->schema([
                Forms\Components\RichEditor::make('body_gu')->label('Content (Gujarati)')->required(),
                Forms\Components\RichEditor::make('body_hi')->label('Content (Hindi)'),
                Forms\Components\RichEditor::make('body_en')->label('Content (English)'),
            ]),

            Forms\Components\Section::make('SEO & Settings')->schema([
                Forms\Components\FileUpload::make('featured_image_path')->image()->directory('pages'),
                Forms\Components\TextInput::make('meta_title')->maxLength(255),
                Forms\Components\Textarea::make('meta_description')->rows(2),
                Forms\Components\Select::make('parent_slug')->options(fn () => Page::pluck('title_gu', 'slug')->toArray())->searchable(),
                Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
                Forms\Components\Select::make('status')->options(['draft' => 'Draft', 'published' => 'Published'])->default('draft')->required(),
                Forms\Components\Select::make('template')->options(['default' => 'Default'])->default('default'),
                Forms\Components\DateTimePicker::make('published_at'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('title_gu')->label('Title')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('slug')->searchable(),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'published' => 'success', default => 'warning',
                }),
                Tables\Columns\TextColumn::make('published_at')->dateTime('d M Y'),
            ])
            ->defaultSort('sort_order')
            ->actions([Tables\Actions\EditAction::make()])
            ->bulkActions([Tables\Actions\BulkActionGroup::make([Tables\Actions\DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListPages::route('/'),
            'create' => Pages\CreatePage::route('/create'),
            'edit' => Pages\EditPage::route('/{record}/edit'),
        ];
    }
}
