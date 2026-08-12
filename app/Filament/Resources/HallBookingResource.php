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
    protected static ?string $navigationGroup = 'Booking & Donation Reports';
    protected static ?int $navigationSort = 40;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Form $form): Form
    {
        return $form->schema([
            Forms\Components\Section::make('Hall')->schema([
                Forms\Components\Placeholder::make('hall_label')
                    ->label('Hall')
                    ->content(fn ($record) => $record?->hall?->name ?? '—'),
                Forms\Components\Placeholder::make('devotee_label')
                    ->label('Booked By')
                    ->content(fn ($record) => $record?->devotee?->name ?? '—'),
            ])->columns(2),
            Forms\Components\Section::make('Booking Details')->schema([
                Forms\Components\TextInput::make('contact_name')->disabled(),
                Forms\Components\TextInput::make('contact_phone')->disabled(),
                Forms\Components\TextInput::make('purpose')->disabled(),
                // booking_date is the RANGE START (item 4.2). It keeps its
                // name; end_date/days_count are read-only companions.
                Forms\Components\DatePicker::make('booking_date')->label('From')->disabled(),
                Forms\Components\DatePicker::make('end_date')->label('To')->disabled(),
                Forms\Components\TextInput::make('days_count')->label('Days')->disabled(),
                Forms\Components\TextInput::make('booking_type')->disabled(),
                Forms\Components\TextInput::make('expected_guests')->disabled(),
                // GST snapshot. Hidden entirely on bookings taken before GST
                // was switched on, so historical rows don't sprout empty
                // tax fields that imply they were somehow taxed at zero.
                Forms\Components\TextInput::make('subtotal_amount')
                    ->label('Taxable value')
                    ->prefix('₹')
                    ->disabled()
                    ->visible(fn ($record) => $record?->gst_rate !== null),
                Forms\Components\TextInput::make('gst_amount')
                    ->label(fn ($record) => 'GST'.($record?->gst_rate !== null ? ' @ '.rtrim(rtrim(number_format((float) $record->gst_rate, 2), '0'), '.').'%' : ''))
                    ->prefix('₹')
                    ->disabled()
                    ->visible(fn ($record) => $record?->gst_rate !== null),
                Forms\Components\TextInput::make('total_amount')
                    ->label('Total charged')
                    ->prefix('₹')
                    ->disabled()
                    ->helperText(fn ($record) => $record?->gst_rate !== null ? 'Taxable value + GST.' : null),
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
            // Shown ONLY while a devotee request is open, so it reads as a
            // task to action rather than a dormant field. The decision
            // itself is the two header actions, not this section.
            Forms\Components\Section::make('Cancellation requested by the devotee')
                ->icon('heroicon-o-exclamation-triangle')
                ->description('The devotee has asked the trust to cancel. The booking is still confirmed and the date is still blocked — approve or decline it with the buttons at the top of this page.')
                ->visible(fn ($record) => $record?->cancel_requested_at !== null && $record?->cancel_responded_at === null)
                ->schema([
                    Forms\Components\Placeholder::make('cancel_requested_at_label')
                        ->label('Requested at')
                        ->content(fn ($record) => $record?->cancel_requested_at?->format('d M Y, H:i') ?? '—'),
                    Forms\Components\Placeholder::make('cancel_reason_label')
                        ->label('Reason given')
                        ->content(fn ($record) => $record?->cancel_reason ?: 'No reason given'),
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
                // Sorts on booking_date (the range start) so defaultSort()
                // and the existing index keep working.
                Tables\Columns\TextColumn::make('booking_date')
                    ->label('Dates')
                    ->sortable()
                    ->getStateUsing(fn (HallBooking $record): string => $record->date_range_label),
                Tables\Columns\TextColumn::make('days_count')
                    ->label('Days')
                    ->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('purpose')->limit(30),
                Tables\Columns\TextColumn::make('total_amount')->prefix('₹'),
                Tables\Columns\IconColumn::make('cancel_requested_at')
                    ->label('Cancel req.')
                    ->boolean()
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('danger')
                    ->falseColor('gray')
                    // TRUE only for OPEN requests — an answered one is history.
                    ->getStateUsing(fn (HallBooking $record): bool => $record->cancel_requested_at !== null
                        && $record->cancel_responded_at === null)
                    ->tooltip(fn (HallBooking $record): ?string => $record->cancel_requested_at !== null
                        && $record->cancel_responded_at === null
                            ? ($record->cancel_reason ?: 'No reason given')
                            : null),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn ($state) => match ($state) {
                    'confirmed' => 'success', 'pending' => 'warning',
                    'cancelled' => 'danger', 'completed' => 'info', default => 'gray',
                }),
            ])
            ->defaultSort('booking_date', 'desc')
            ->filters([
                Tables\Filters\Filter::make('cancellation_requested')
                    ->label('Cancellation requested')
                    ->query(fn (\Illuminate\Database\Eloquent\Builder $query): \Illuminate\Database\Eloquent\Builder => $query
                        ->whereNotNull('cancel_requested_at')
                        ->whereNull('cancel_responded_at'))
                    ->toggle(),
                Tables\Filters\SelectFilter::make('status')->options([
                    'pending' => 'Pending', 'confirmed' => 'Confirmed',
                    'cancelled' => 'Cancelled', 'completed' => 'Completed',
                ]),
            ])
            ->actions([Tables\Actions\EditAction::make()]);
    }

    public static function getEloquentQuery(): \Illuminate\Database\Eloquent\Builder
    {
        return parent::getEloquentQuery()->with(['hall', 'devotee', 'payment']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListHallBookings::route('/'),
            'edit' => Pages\EditHallBooking::route('/{record}/edit'),
        ];
    }
}
