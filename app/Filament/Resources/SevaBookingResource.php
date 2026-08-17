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
use Illuminate\Database\Eloquent\Builder;

class SevaBookingResource extends Resource
{
    protected static ?string $model = SevaBooking::class;

    protected static ?string $navigationIcon = 'heroicon-o-bookmark';

    protected static ?string $navigationGroup = 'Booking & Donation Reports';

    protected static ?int $navigationSort = 20;

    protected static ?string $modelLabel = 'Seva Booking';

    protected static ?string $pluralModelLabel = 'Seva Bookings';

    public static function canCreate(): bool
    {
        // Bookings come in via the app/web; admin should never create one manually.
        // Belt-and-braces: SevaBookingPolicy::create() ALSO denies (no role has
        // create_seva::booking by default). Leave this hard return so even a
        // super admin can't accidentally insert a booking with no payment.
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
                    // slot_time_label, not slot_time — full-day/full-week
                    // bookings store a sentinel there, not a clock time.
                    ->content(fn ($record) => $record?->slot_time_label ?? '—'),
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
                Tables\Columns\TextColumn::make('slot_time_label')
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
                // "Show me every booking for this seva" is the question this
                // list gets asked most — the seva was visible as a column but
                // there was no way to isolate one (2026-08-17).
                // ->get()->pluck() because `name` is a localized accessor: a
                // raw pluck selects a column that does not exist.
                Tables\Filters\SelectFilter::make('seva_id')
                    ->label('Seva')
                    ->options(fn (): array => \App\Models\Seva::query()
                        ->orderBy('sort_order')
                        ->get()
                        ->pluck('name', 'id')
                        ->all())
                    ->searchable()
                    ->preload(),
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
                    // Type-hint Builder so Filament's container can
                    // inject the query — without it, Filament 3 tries
                    // to resolve "$q" by name and throws
                    // BindingResolutionException on the AJAX call.
                    ->query(fn (Builder $query) => $query->where('booking_date', '>=', now()->toDateString())),
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
                        // G17 (2026-08-09): custom BulkActions are NOT
                        // auto-authorized by Filament the way DeleteBulkAction
                        // is — this mutated booking status for anyone holding
                        // view_any_seva::booking, which includes the
                        // deliberately read-only `pujari` and `volunteer`.
                        ->visible(fn (): bool => auth('admin')->user()?->can('update_seva::booking') ?? false)
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
