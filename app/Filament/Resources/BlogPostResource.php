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
    protected static ?string $navigationGroup = 'Content';
    protected static ?int $navigationSort = 4;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Post Info')->schema([
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
                Forms\Components\Textarea::make('excerpt_gu')->label('Excerpt (Gujarati)')->rows(2),
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
                Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'published' ? 'success' : 'warning'),
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
