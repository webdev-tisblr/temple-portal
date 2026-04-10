<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Enums\OrderStatus;
use App\Filament\Resources\OrderResource\Pages;
use App\Models\Order;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class OrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $navigationIcon = 'heroicon-o-shopping-cart';

    protected static ?string $navigationGroup = 'Temple Store';

    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([]);
    }

    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist->schema([
            Infolists\Components\Section::make('Order Details')->schema([
                Infolists\Components\TextEntry::make('order_number')->label('Order Number'),
                Infolists\Components\TextEntry::make('created_at')->dateTime('d M Y, h:i A')->label('Date'),
                Infolists\Components\TextEntry::make('devotee.name')->label('Devotee'),
                Infolists\Components\TextEntry::make('devotee.phone')->label('Phone'),
                Infolists\Components\TextEntry::make('status')->badge()->label('Status'),
                Infolists\Components\TextEntry::make('total_amount')->prefix('₹')->label('Total Amount'),
            ])->columns(2),

            Infolists\Components\Section::make('Shipping Address')->schema([
                Infolists\Components\TextEntry::make('shipping_name')->label('Name'),
                Infolists\Components\TextEntry::make('shipping_phone')->label('Phone'),
                Infolists\Components\TextEntry::make('shipping_address')->label('Address'),
                Infolists\Components\TextEntry::make('shipping_city')->label('City'),
                Infolists\Components\TextEntry::make('shipping_state')->label('State'),
                Infolists\Components\TextEntry::make('shipping_pincode')->label('Pincode'),
            ])->columns(2),

            Infolists\Components\Section::make('Items')->schema([
                Infolists\Components\RepeatableEntry::make('items')->schema([
                    Infolists\Components\TextEntry::make('product_name')->label('Product'),
                    Infolists\Components\TextEntry::make('quantity')->label('Qty'),
                    Infolists\Components\TextEntry::make('unit_price')->prefix('₹')->label('Unit Price'),
                    Infolists\Components\TextEntry::make('subtotal')->prefix('₹')->label('Subtotal'),
                ])->columns(4),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('order_number')->label('Order #')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('devotee.name')->label('Devotee')->searchable(),
                Tables\Columns\TextColumn::make('total_amount')->prefix('₹')->sortable()
                    ->summarize(Tables\Columns\Summarizers\Sum::make()->prefix('₹')),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    OrderStatus::PENDING => 'warning',
                    OrderStatus::CONFIRMED => 'info',
                    OrderStatus::PROCESSING => 'primary',
                    OrderStatus::SHIPPED => 'success',
                    OrderStatus::DELIVERED => 'success',
                    OrderStatus::CANCELLED => 'danger',
                    OrderStatus::REFUNDED => 'gray',
                    default => 'gray',
                }),
                Tables\Columns\TextColumn::make('created_at')->dateTime('d M Y H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')
                    ->options(collect(OrderStatus::cases())->mapWithKeys(fn ($s) => [$s->value => ucfirst($s->value)])),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListOrders::route('/'),
            'view' => Pages\ViewOrder::route('/{record}'),
        ];
    }
}
