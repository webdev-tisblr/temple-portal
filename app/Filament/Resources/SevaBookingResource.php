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
        // Filament's EditRecord::fillForm() only fills with $record->attributesToArray(),
        // which excludes relations — so dot-notation TextInputs (e.g. `seva.name_en`)
        // render blank. Use Placeholder::content() with a closure to read the
        // relation off the loaded model directly.
        return $form->schema([
            Forms\Components\Section::make('Booking')->schema([
                Forms\Components\Placeholder::make('seva_label')
                    ->label('Seva')
                    ->content(fn ($record) => $record?->seva?->name_en ?? '—'),
                Forms\Components\Placeholder::make('devotee_label')
                    ->label('Devotee')
                    ->content(fn ($record) => $record?->devotee?->name ?? '—'),
                Forms\Components\Placeholder::make('devotee_phone_label')
                    ->label('Phone')
                    ->content(fn ($record) => $record?->devotee?->phone ?? '—'),
                Forms\Components\Placeholder::make('booking_date_label')
                    ->label('Booking Date')
                    ->content(fn ($record) => $record?->booking_date?->format('d M Y') ?? '—'),
                Forms\Components\Placeholder::make('slot_time_label')
                    ->label('Slot Time')
                    ->content(fn ($record) => $record?->slot_time ?? '—'),
                Forms\Components\Placeholder::make('quantity_label')
                    ->label('Quantity')
                    ->content(fn ($record) => (string) ($record?->quantity ?? '—')),
                Forms\Components\Placeholder::make('total_amount_label')
                    ->label('Total Amount')
                    ->content(fn ($record) => $record?->total_amount !== null
                        ? '₹' . number_format((float) $record->total_amount, 2)
                        : '—'),
                Forms\Components\Placeholder::make('selected_product_label')
                    ->label('Selected Product')
                    ->content(fn ($record) => $record?->selectedProduct?->name_en ?? '—'),
            ])->columns(2),

            Forms\Components\Section::make('Devotee Details')->schema([
                Forms\Components\Placeholder::make('devotee_name_for_seva_label')
                    ->label('Name for Seva')
                    ->content(fn ($record) => $record?->devotee_name_for_seva ?? '—'),
                Forms\Components\Placeholder::make('gotra_label')
                    ->label('Gotra')
                    ->content(fn ($record) => $record?->gotra ?? '—'),
                Forms\Components\Placeholder::make('sankalp_label')
                    ->label('Sankalp')
                    ->content(fn ($record) => $record?->sankalp ?? '—')
                    ->columnSpanFull(),
            ])->columns(2),

            Forms\Components\Section::make('Payment')->schema([
                Forms\Components\Placeholder::make('razorpay_payment_id_label')
                    ->label('Razorpay Payment ID')
                    ->content(fn ($record) => $record?->payment?->razorpay_payment_id ?? '—'),
                Forms\Components\Placeholder::make('razorpay_order_id_label')
                    ->label('Razorpay Order ID')
                    ->content(fn ($record) => $record?->payment?->razorpay_order_id ?? '—'),
                Forms\Components\Placeholder::make('payment_status_label')
                    ->label('Payment Status')
                    ->content(fn ($record) => $record?->payment?->status?->value ?? '—'),
                Forms\Components\Placeholder::make('payment_method_label')
                    ->label('Method')
                    ->content(fn ($record) => $record?->payment?->method ?? '—'),
                Forms\Components\Placeholder::make('payment_amount_label')
                    ->label('Paid Amount')
                    ->content(fn ($record) => $record?->payment?->amount !== null
                        ? '₹' . number_format((float) $record->payment->amount, 2)
                        : '—'),
                Forms\Components\Placeholder::make('paid_at_label')
                    ->label('Paid At')
                    ->content(fn ($record) => $record?->payment?->paid_at?->format('d M Y, H:i') ?? '—'),
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
