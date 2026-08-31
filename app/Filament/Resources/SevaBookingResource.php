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
            ])->columns(2),

            // Read-only: neither flag is an admin decision. `wants_80g` is
            // what the devotee ticked at booking, and `is_80g_eligible` is
            // the strict PAN gate's verdict at capture. Editing either here
            // would let a statutory receipt be issued (or withdrawn)
            // without the rule that governs it ever running.
            Forms\Components\Section::make('80G')->schema([
                Forms\Components\Placeholder::make('wants_80g_label')
                    ->label('Devotee asked for 80G')
                    ->content(fn ($record) => $record?->wants_80g ? 'Yes' : 'No'),
                Forms\Components\Placeholder::make('receipt_80g_label')
                    ->label('80G Receipt No.')
                    ->content(fn ($record) => $record?->receipt80G?->receipt_number
                        ?? ($record?->wants_80g
                            ? 'Not issued — no valid PAN on the devotee profile'
                            : '—')),
                Forms\Components\Placeholder::make('seva_receipt_label')
                    ->label('Seva Receipt No.')
                    ->content(fn ($record) => $record?->receipt_number ?? '—'),
            ])->columns(3),

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
                // Shows what the devotee GOT, not what they asked for: the
                // tick alone means nothing if the PAN gate refused.
                Tables\Columns\IconColumn::make('receipt80G')
                    ->label('80G')
                    ->boolean()
                    ->getStateUsing(fn ($record) => $record->receipt80G !== null)
                    ->trueIcon('heroicon-o-document-check')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->toggleable(),
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
                // Paid-only, ON by default — the same treatment the donations
                // list has had (2026-08-17). A booking sits at `pending` while
                // the devotee is in Razorpay and is flipped to `cancelled` by
                // bookings:clean-stale when they never finish, so the list was
                // padded with abandoned checkouts that no one ever paid for
                // and that the temple must not prepare anything for.
                //
                // `refunded` stays IN: the money genuinely moved, and hiding
                // it would erase a real transaction from the day's view.
                // Operators untick this to chase an abandoned booking.
                Tables\Filters\Filter::make('paid_only')
                    ->label('Paid bookings only')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query
                        ->whereIn('status', ['confirmed', 'completed', 'refunded'])),
                // "Show me every booking for this seva" is the question this
                // list gets asked most — the seva was visible as a column but
                // there was no way to isolate one (2026-08-17).
                // ->get()->pluck() because `name` is a localized accessor: a
                // raw pluck selects a column that does not exist.
                // Two DIFFERENT questions, so two filters rather than one
                // tri-state: "who asked" is the demand signal the trust
                // wants, "who was refused" is the follow-up list — those
                // devotees can still be issued a receipt by adding their
                // PAN and regenerating.
                Tables\Filters\TernaryFilter::make('wants_80g')
                    ->label('Asked for 80G')
                    ->placeholder('All bookings')
                    ->trueLabel('Asked for an 80G receipt')
                    ->falseLabel('Did not ask'),
                Tables\Filters\Filter::make('missing_80g_receipt')
                    ->label('Asked for 80G but got none')
                    ->query(fn (Builder $query) => $query
                        ->where('wants_80g', true)
                        ->whereDoesntHave('receipt80G')),
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
        // receipt80G eager-loaded for the 80G column and the display
        // receipt number — without it the table lazy-loads once per row.
        return parent::getEloquentQuery()->with(['seva', 'devotee', 'payment', 'selectedProduct', 'receipt80G']);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListSevaBookings::route('/'),
            'edit' => Pages\EditSevaBooking::route('/{record}/edit'),
        ];
    }
}
