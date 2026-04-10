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

    protected static ?string $navigationGroup = 'Temple Store';

    protected static ?int $navigationSort = 2;

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Basic Info')->schema([
                Forms\Components\TextInput::make('name_gu')->label('Name (Gujarati)')->required()->maxLength(255),
                Forms\Components\TextInput::make('name_hi')->label('Name (Hindi)')->required()->maxLength(255),
                Forms\Components\TextInput::make('name_en')->label('Name (English)')->required()->maxLength(255)
                    ->afterStateUpdated(fn (Set $set, ?string $state) => $set('slug', Str::slug($state ?? ''))),
                Forms\Components\TextInput::make('slug')->required()->unique(ignoreRecord: true)->maxLength(255),
                Forms\Components\Select::make('category_id')
                    ->label('Category')
                    ->relationship('category', 'name_gu')
                    ->searchable()
                    ->preload()
                    ->required(),
            ])->columns(2),

            Forms\Components\Section::make('Description')->schema([
                Forms\Components\RichEditor::make('description_gu')->label('Description (Gujarati)'),
                Forms\Components\RichEditor::make('description_hi')->label('Description (Hindi)'),
                Forms\Components\RichEditor::make('description_en')->label('Description (English)'),
            ]),

            Forms\Components\Section::make('Pricing & Stock')->schema([
                Forms\Components\Toggle::make('has_variants')->label('Variable Pricing (multiple options)')
                    ->helperText('Enable for products with size/weight variants (e.g., 250gm, 500gm, 1kg).')
                    ->live()
                    ->columnSpanFull(),
                Forms\Components\TextInput::make('price')->numeric()->prefix('₹')->required()
                    ->label(fn (Forms\Get $get) => $get('has_variants') ? 'Starting Price (display)' : 'Price')
                    ->helperText(fn (Forms\Get $get) => $get('has_variants') ? 'Shown as "₹xxx+" on listings. Set to lowest variant price.' : ''),
                Forms\Components\TextInput::make('stock_quantity')->numeric()->minValue(0)->required()->default(0),
                Forms\Components\Repeater::make('variants')
                    ->label('Price Variants')
                    ->schema([
                        Forms\Components\TextInput::make('label')->label('Option Label')
                            ->required()->placeholder('e.g. 250 gm, 500 gm, 1 kg')
                            ->maxLength(100),
                        Forms\Components\TextInput::make('price')->label('Price (₹)')
                            ->required()->numeric()->prefix('₹')->minValue(1),
                    ])
                    ->columns(2)
                    ->defaultItems(0)
                    ->addActionLabel('Add Variant')
                    ->visible(fn (Forms\Get $get) => (bool) $get('has_variants'))
                    ->columnSpanFull()
                    ->helperText('Add all size/weight/quantity options with their prices.'),
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
                Tables\Columns\TextColumn::make('stock_quantity')->label('Stock'),
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
