<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\ProductResource\Pages;
use App\Models\Product;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Forms\Set;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class ProductResource extends Resource
{

    protected static ?string $model = Product::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-bag';

    protected static ?string $navigationGroup = 'Store';

    protected static ?int $navigationSort = 20;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Basic Info')->schema([
                \App\Filament\Support\TranslatableTabs::make(function (string $locale, string $label) {
                    $name = Forms\Components\TextInput::make("name_{$locale}")->label("Name {$label}")->required()->maxLength(255);
                    if ($locale === 'en') {
                        $name->live(onBlur: true)
                            ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state ?? '')));
                    }

                    return [
                        $name,
                        Forms\Components\RichEditor::make("description_{$locale}")->label("Description {$label}"),
                    ];
                }),
                Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(255),
                Forms\Components\Select::make('category_id')
                    ->label('Category')
                    ->relationship('category', 'name_gu')
                    ->searchable()
                    ->preload()
                    ->required(),
            ])->columns(2),

            Forms\Components\Section::make('Pricing & Stock')->schema([
                Forms\Components\Toggle::make('has_variants')->label('Variable Pricing (multiple options)')
                    ->helperText('Enable for products with size/weight variants (e.g., 250gm, 500gm, 1kg). Each variant tracks its own price AND stock count.')
                    ->live()
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('price')->numeric()->prefix('₹')->required()
                    ->label(fn (Forms\Get $get) => $get('has_variants') ? 'Starting Price (display)' : 'Price')
                    ->helperText(fn (Forms\Get $get) => $get('has_variants') ? 'Shown as "₹xxx+" on listings. Set to lowest variant price.' : ''),
                // Untracked products are always purchasable — no counts,
                // nothing decremented on sale, no restock on cancel.
                Forms\Components\Toggle::make('track_stock')
                    ->label('Track Stock')
                    ->default(true)
                    ->live()
                    ->helperText('Off = unlimited availability (no stock counts anywhere). On = manage stock below; sales reduce it and the product shows Out of Stock at zero.')
                    ->columnSpanFull(),
                // Top-level stock — only meaningful for non-variant products.
                // Hidden when has_variants is on so admins don't accidentally
                // edit it (variant stock is on each row below instead).
                Forms\Components\TextInput::make('stock_quantity')
                    ->numeric()
                    ->minValue(0)
                    ->required(fn (Forms\Get $get) => $get('track_stock') && ! $get('has_variants'))
                    ->default(0)
                    ->visible(fn (Forms\Get $get) => $get('track_stock') && ! $get('has_variants'))
                    ->helperText('Total units in stock. For variable products, set stock on each variant below instead.'),
                Forms\Components\Repeater::make('variants')
                    ->label('Price & Stock Variants')
                    ->schema([
                        Forms\Components\TextInput::make('label')->label('Option Label')
                            ->required()->placeholder('e.g. 250 gm, 500 gm, 1 kg')
                            ->maxLength(100),
                        Forms\Components\TextInput::make('price')->label('Price (₹)')
                            ->required()->numeric()->prefix('₹')->minValue(1),
                        // Per-variant stock. Variable products gate
                        // add-to-cart against THIS number, not stock_quantity.
                        // Hidden entirely for untracked products.
                        Forms\Components\TextInput::make('stock')->label('Stock')
                            ->required(fn (Forms\Get $get) => (bool) $get('../../track_stock'))
                            ->numeric()->minValue(0)->default(0)
                            ->visible(fn (Forms\Get $get) => (bool) $get('../../track_stock'))
                            ->helperText('Units available for this option.'),
                    ])
                    ->columns(3)
                    ->defaultItems(0)
                    ->addActionLabel('Add Variant')
                    ->visible(fn (Forms\Get $get) => (bool) $get('has_variants'))
                    ->columnSpanFull()
                    ->helperText('Add all size/weight/quantity options with their prices AND individual stock counts.'),
            ])->columns(2),

            Forms\Components\Section::make('Images')->schema([
                Forms\Components\FileUpload::make('image_path')->label('Primary Image')->image()->directory('products')->maxSize(2048),
                Forms\Components\Repeater::make('images')
                    ->relationship()
                    ->schema([
                        Forms\Components\FileUpload::make('image_path')->label('Image')->image()->directory('product-images')->maxSize(2048),
                        Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
                    ])
                    ->defaultItems(0)
                    ->addActionLabel('Add Image'),
            ]),

            Forms\Components\Section::make('Status')->schema([
                Forms\Components\Toggle::make('is_active')->label('Active')->default(true),
                Forms\Components\Toggle::make('is_featured')->label('Featured')->default(false),
                Forms\Components\Toggle::make('is_seva_only')->label('Seva Only (Unlisted)')
                    ->helperText('Hide from store. Only shown as selection options in seva booking.')
                    ->default(false),
                Forms\Components\TextInput::make('sort_order')->numeric()->default(0),
            ])->columns(4),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\ImageColumn::make('image_path')->label('Image')->circular(),
                Tables\Columns\TextColumn::make('name_gu')->label('Name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('category.name_gu')->label('Category'),
                Tables\Columns\TextColumn::make('price')->prefix('₹')->sortable()
                    ->description(fn (Product $record) => $record->has_variants ? 'Variable' : null),
                Tables\Columns\TextColumn::make('stock_quantity')->label('Stock')
                    // Untracked → ∞. Variable products: sum each variant's
                    // stock so the admin sees a total at-a-glance.
                    // Non-variant products just show the column value.
                    ->state(fn (Product $record) => ! $record->track_stock
                        ? '∞'
                        : ($record->has_variants
                            ? (string) collect($record->variants ?? [])->sum(fn ($v) => (int) ($v['stock'] ?? 0))
                            : (string) $record->stock_quantity))
                    ->description(fn (Product $record) => ! $record->track_stock
                        ? 'not tracked'
                        : ($record->has_variants
                            ? 'across ' . count($record->variants ?? []) . ' variants'
                            : null)),
                Tables\Columns\ToggleColumn::make('is_active')->label('Active'),
                Tables\Columns\IconColumn::make('is_featured')->label('Featured')->boolean(),
                Tables\Columns\IconColumn::make('is_seva_only')->label('Seva Only')->boolean()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('sort_order')->sortable(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                Tables\Filters\SelectFilter::make('category')
                    ->relationship('category', 'name_gu'),
                Tables\Filters\TernaryFilter::make('is_active')->label('Active'),
                Tables\Filters\TernaryFilter::make('is_featured')->label('Featured'),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
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
            'index' => Pages\ListProducts::route('/'),
            'create' => Pages\CreateProduct::route('/create'),
            'edit' => Pages\EditProduct::route('/{record}/edit'),
        ];
    }
}
