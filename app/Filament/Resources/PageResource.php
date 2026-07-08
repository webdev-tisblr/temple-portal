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

    /**
     * The block palette for the page builder — shared across all three
     * language Builders. Each block stores {type, data} in the blocks_* JSON.
     */
    private static function contentBlocks(): array
    {
        return [
            Forms\Components\Builder\Block::make('heading')
                ->icon('heroicon-o-bars-3')
                ->schema([
                    Forms\Components\Select::make('level')->options(['h2' => 'Large heading', 'h3' => 'Medium heading'])->default('h2'),
                    Forms\Components\TextInput::make('text')->required()->maxLength(255),
                ]),
            Forms\Components\Builder\Block::make('paragraph')
                ->icon('heroicon-o-bars-3-bottom-left')
                ->schema([
                    Forms\Components\RichEditor::make('html')->label('Text')->required(),
                ]),
            Forms\Components\Builder\Block::make('image')
                ->icon('heroicon-o-photo')
                ->schema([
                    Forms\Components\FileUpload::make('path')->label('Image')->image()->directory('page-blocks')->maxSize(4096)->required(),
                    Forms\Components\TextInput::make('caption')->maxLength(255),
                ]),
            Forms\Components\Builder\Block::make('list')
                ->icon('heroicon-o-list-bullet')
                ->schema([
                    Forms\Components\Repeater::make('items')
                        ->simple(Forms\Components\TextInput::make('item')->required())
                        ->defaultItems(2)
                        ->addActionLabel('Add item'),
                ]),
            Forms\Components\Builder\Block::make('quote')
                ->icon('heroicon-o-chat-bubble-left')
                ->schema([
                    Forms\Components\Textarea::make('text')->required()->rows(2),
                ]),
            Forms\Components\Builder\Block::make('video')
                ->icon('heroicon-o-play-circle')
                ->schema([
                    Forms\Components\TextInput::make('url')->label('YouTube URL')->url()->required(),
                ]),
        ];
    }

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

            Forms\Components\Section::make('Content Blocks')
                ->description('Build the page from blocks — drag to reorder. Gujarati is primary; Hindi/English fall back to Gujarati when left empty.')
                ->schema([
                    Forms\Components\Tabs::make('content')->tabs([
                        Forms\Components\Tabs\Tab::make('Gujarati')->schema([
                            Forms\Components\Builder::make('blocks_gu')->label('')->blocks(self::contentBlocks())->blockNumbers(false)->collapsible()->cloneable(),
                        ]),
                        Forms\Components\Tabs\Tab::make('Hindi')->schema([
                            Forms\Components\Builder::make('blocks_hi')->label('')->blocks(self::contentBlocks())->blockNumbers(false)->collapsible()->cloneable(),
                        ]),
                        Forms\Components\Tabs\Tab::make('English')->schema([
                            Forms\Components\Builder::make('blocks_en')->label('')->blocks(self::contentBlocks())->blockNumbers(false)->collapsible()->cloneable(),
                        ]),
                    ]),
                ]),

            Forms\Components\Section::make('Legacy HTML (optional)')
                ->description('Older pages used plain HTML. If content blocks exist above, they take priority and this is ignored.')
                ->collapsed()
                ->schema([
                    Forms\Components\RichEditor::make('body_gu')->label('Content (Gujarati)'),
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
