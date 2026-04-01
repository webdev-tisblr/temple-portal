<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\HallBookingResource\Pages;
use App\Models\HallBooking;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class HallBookingResource extends Resource
{
    protected static ?string $model = HallBooking::class;
    protected static ?string $navigationIcon = 'heroicon-o-building-office';
    protected static ?string $navigationGroup = 'Temple Management';
    protected static ?int $navigationSort = 3;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Booking Details')->schema([
                Forms\Components\TextInput::make('contact_name')->disabled(),
                Forms\Components\TextInput::make('contact_phone')->disabled(),
                Forms\Components\TextInput::make('purpose')->disabled(),
                Forms\Components\DatePicker::make('booking_date')->disabled(),
                Forms\Components\TextInput::make('booking_type')->disabled(),
                Forms\Components\TextInput::make('expected_guests')->disabled(),
                Forms\Components\TextInput::make('total_amount')->prefix('₹')->disabled(),
            ])->columns(2),
            Forms\Components\Section::make('Admin')->schema([
                Forms\Components\Select::make('status')->options([
                    'pending' => 'Pending', 'confirmed' => 'Confirmed',
                    'cancelled' => 'Cancelled', 'completed' => 'Completed',
                ])->required(),
                Forms\Components\Textarea::make('admin_notes')->rows(3),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('hall.name')->label('Hall')->sortable(),
                Tables\Columns\TextColumn::make('contact_name')->label('Contact')->searchable(),
                Tables\Columns\TextColumn::make('booking_date')->date('d M Y')->sortable(),
                Tables\Columns\TextColumn::make('purpose')->limit(30),
                Tables\Columns\TextColumn::make('total_amount')->prefix('₹'),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'confirmed' => 'success', 'pending' => 'warning',
                    'cancelled' => 'danger', 'completed' => 'info', default => 'gray',
                }),
            ])
            ->defaultSort('booking_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending' => 'Pending', 'confirmed' => 'Confirmed',
                    'cancelled' => 'Cancelled', 'completed' => 'Completed',
                ]),
            ])
            ->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHallBookings::route('/'),
            'edit' => Pages\EditHallBooking::route('/{record}/edit'),
        ];
    }
}
