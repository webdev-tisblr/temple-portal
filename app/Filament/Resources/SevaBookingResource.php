<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\SevaBookingResource\Pages;
use App\Models\SevaBooking;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;

class SevaBookingResource extends Resource
{
    protected static ?string $model = SevaBooking::class;

    protected static ?string $navigationIcon = 'heroicon-o-bookmark';

    protected static ?string $navigationGroup = 'Temple Management';

    protected static ?int $navigationSort = 2;

    protected static ?string $modelLabel = 'Seva Booking';

    protected static ?string $pluralModelLabel = 'Seva Bookings';

    public static function canCreate(): bool
    {
        // Bookings come in via the app/web; admin should never create one manually.
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Booking')->schema([
                Forms\Components\TextInput::make('seva.name_en')
                    ->label('Seva')
                    ->disabled()
                    ->dehydrated(false),
                Forms\Components\TextInput::make('devotee.name')
                    ->label('Devotee')
                    ->disabled()
                    ->dehydrated(false),
                Forms\Components\TextInput::make('devotee.phone')
                    ->label('Phone')
                    ->disabled()
                    ->dehydrated(false),
                Forms\Components\DatePicker::make('booking_date')
                    ->disabled(),
                Forms\Components\TextInput::make('slot_time')
                    ->disabled(),
                Forms\Components\TextInput::make('quantity')
                    ->disabled(),
                Forms\Components\TextInput::make('total_amount')
                    ->prefix('₹')
                    ->disabled(),
                Forms\Components\TextInput::make('selectedProduct.name_en')
                    ->label('Selected Product')
                    ->disabled()
                    ->dehydrated(false),
            ])->columns(2),

            Forms\Components\Section::make('Devotee Details')->schema([
                Forms\Components\TextInput::make('devotee_name_for_seva')
                    ->label('Name for Seva')
                    ->disabled(),
                Forms\Components\TextInput::make('gotra')->disabled(),
                Forms\Components\Textarea::make('sankalp')
                    ->disabled()
                    ->rows(3)
                    ->columnSpanFull(),
            ])->columns(2),

            Forms\Components\Section::make('Payment')->schema([
                Forms\Components\TextInput::make('payment.razorpay_payment_id')
                    ->label('Razorpay Payment ID')
                    ->disabled()
                    ->dehydrated(false),
                Forms\Components\TextInput::make('payment.status')
                    ->label('Payment Status')
                    ->disabled()
                    ->dehydrated(false),
                Forms\Components\DateTimePicker::make('payment.paid_at')
                    ->label('Paid At')
                    ->disabled()
                    ->dehydrated(false),
            ])->columns(3),

            Forms\Components\Section::make('Admin')->schema([
                Forms\Components\Select::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'confirmed' => 'Confirmed',
                        'completed' => 'Completed',
                        'cancelled' => 'Cancelled',
                        'refunded' => 'Refunded',
                    ])
                    ->required(),
                Forms\Components\DateTimePicker::make('cancelled_at')
                    ->label('Cancelled At'),
                Forms\Components\Textarea::make('cancellation_reason')
                    ->rows(2)
                    ->columnSpanFull(),
                Forms\Components\Textarea::make('notes')
                    ->label('Internal notes')
                    ->rows(3)
                    ->columnSpanFull(),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('booking_date')
                    ->date('d M Y')
                    ->sortable(),
                Tables\Columns\TextColumn::make('slot_time')
                    ->label('Slot')
                    ->placeholder('—'),
                Tables\Columns\TextColumn::make('seva.name_en')
                    ->label('Seva')
                    ->searchable(),
                Tables\Columns\TextColumn::make('devotee.name')
                    ->label('Devotee')
                    ->searchable(),
                Tables\Columns\TextColumn::make('devotee.phone')
                    ->label('Phone')
                    ->searchable()
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('selectedProduct.name_en')
                    ->label('Product')
                    ->placeholder('—')
                    ->limit(20)
                    ->toggleable(),
                Tables\Columns\TextColumn::make('total_amount')
                    ->prefix('₹')
                    ->sortable(),
                Tables\Columns\TextColumn::make('payment.status')
                    ->label('Payment')
                    ->badge()
                    ->formatStateUsing(fn ($state) => $state instanceof \BackedEnum ? $state->value : $state)
                    ->color(function ($state) {
                        $value = $state instanceof \BackedEnum ? $state->value : (string) $state;
                        return match ($value) {
                            'captured' => 'success',
                            'created', 'authorized' => 'warning',
                            'failed' => 'danger',
                            'refunded' => 'gray',
                            default => 'gray',
                        };
                    }),
                Tables\Columns\TextColumn::make('status')
                    ->badge()
                    ->color(fn ($state) => match ($state instanceof \BackedEnum ? $state->value : (string) $state) {
                        'confirmed' => 'success',
                        'completed' => 'info',
                        'pending' => 'warning',
                        'cancelled' => 'danger',
                        'refunded' => 'gray',
                        default => 'gray',
                    })
                    ->formatStateUsing(fn ($state) => $state instanceof \BackedEnum ? $state->value : $state),
                Tables\Columns\TextColumn::make('created_at')
                    ->dateTime('d M Y, H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('booking_date', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending' => 'Pending',
                    'confirmed' => 'Confirmed',
                    'completed' => 'Completed',
                    'cancelled' => 'Cancelled',
                    'refunded' => 'Refunded',
                ]),
                Tables\Filters\SelectFilter::make('payment_status')
                    ->label('Payment')
                    ->options([
                        'created' => 'Created',
                        'authorized' => 'Authorized',
                        'captured' => 'Captured',
                        'failed' => 'Failed',
                        'refunded' => 'Refunded',
                    ])
                    ->query(function ($query, array $data) {
                        if (! empty($data['value'])) {
                            $query->whereHas('payment', fn ($q) => $q->where('status', $data['value']));
                        }
                    }),
                Tables\Filters\Filter::make('upcoming')
                    ->label('Upcoming only')
                    ->query(fn ($q) => $q->where('booking_date', '>=', now()->toDateString())),
            ])
            ->actions([
                Tables\Actions\EditAction::make(),
            ])
            ->bulkActions([
                Tables\Actions\BulkActionGroup::make([
                    Tables\Actions\BulkAction::make('mark_completed')
                        ->label('Mark as Completed')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->action(fn ($records) => $records->each->update(['status' => 'completed']))
                        ->requiresConfirmation()
                        ->deselectRecordsAfterCompletion(),
                ]),
            ]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['seva', 'devotee', 'payment', 'selectedProduct']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSevaBookings::route('/'),
            'edit' => Pages\EditSevaBooking::route('/{record}/edit'),
        ];
    }
}
