<?php

declare(strict_types=1);

namespace App\Filament\Resources;

use App\Filament\Resources\DonationResource\Pages;
use App\Models\Donation;
use Filament\Forms;
use Filament\Forms\Form;
use Filament\Infolists;
use Filament\Infolists\Infolist;
use Filament\Resources\Resource;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class DonationResource extends Resource
{

    protected static ?string $model = Donation::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'Booking & Donation Reports';

    protected static ?int $navigationSort = 10;

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
            Infolists\Components\Section::make('Donation Details')->schema([
                Infolists\Components\TextEntry::make('id')->label('Donation ID'),
                Infolists\Components\TextEntry::make('created_at')->dateTime('d M Y, h:i A')->label('Date'),
                Infolists\Components\TextEntry::make('devotee.name')->label('Devotee'),
                Infolists\Components\TextEntry::make('devotee.phone')->label('Phone'),
                Infolists\Components\TextEntry::make('devotee.email')->label('Email')->default('-'),
                Infolists\Components\TextEntry::make('amount')->prefix('₹')->label('Amount'),
                Infolists\Components\TextEntry::make('donation_type')->badge()->label('Type'),
                // Item 5.2 — the actual campaign name, not just the generic
                // "Campaign" donation-type badge above. `title` is a localized
                // ACCESSOR on DonationCampaign (not a column), so it is read
                // here via a state closure; the English title is preferred to
                // match the rest of the (English) admin UI.
                Infolists\Components\TextEntry::make('campaign_title')
                    ->label('Campaign')
                    ->state(fn (Donation $record): ?string => $record->campaign
                        ? ($record->campaign->title_en ?: $record->campaign->title_gu)
                        : null)
                    ->default('-'),
                Infolists\Components\TextEntry::make('purpose')->label('Purpose')->default('-'),
                Infolists\Components\TextEntry::make('financial_year')->label('Financial Year'),
                // Item 5.4 — Gupt Daan is a PUBLIC-DISPLAY choice, never a
                // data-collection one: the donor's name/phone/email/address
                // are right above this entry, fully retained, exactly as the
                // trust needs them. What was missing was any way for an
                // operator to KNOW a donation was made anonymously, so a
                // Gupt Daan looked identical to a normal one in admin and
                // staff could not tell why the public donor list showed
                // "રામ ભરોસે".
                Infolists\Components\IconEntry::make('anonymous')
                    ->label('Gupt Daan (donor chose to stay anonymous)')
                    ->boolean()
                    ->helperText('Set ONLY by the donor ticking Gupt Daan at checkout (or by account deletion). It is not a consequence of a missing PAN — a donation with no PAN is simply a donation with no 80G receipt, and its donor is listed by name. Donor details above are always retained; this flag only masks the name on public donor lists.'),
                // The system's 80G verdict under the strict PAN rule. False
                // means no receipt number was ever burned for this donation.
                Infolists\Components\IconEntry::make('is_80g_eligible')
                    ->label('80G eligible')
                    ->boolean()
                    ->helperText('Requires a valid PAN on the donor profile. No PAN → no receipt, no receipt number.'),
                Infolists\Components\TextEntry::make('receipt.receipt_number')->label('Receipt No.')->default('Not generated'),
            ])->columns(2),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                Tables\Columns\TextColumn::make('id')->label('ID')->limit(8)->sortable()->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('devotee.name')->label('Devotee')->searchable(),
                Tables\Columns\TextColumn::make('amount')->prefix('₹')->sortable()->summarize(Tables\Columns\Summarizers\Sum::make()->prefix('₹')),
                Tables\Columns\TextColumn::make('donation_type')->badge()->formatStateUsing(fn ($state) => ucfirst($state->value ?? $state))->color(fn ($state) => match ($state->value ?? $state) {
                    'general' => 'primary',
                    'seva' => 'success',
                    'annadan' => 'warning',
                    'construction' => 'info',
                    'festival' => 'danger',
                    default => 'gray',
                }),
                // Item 5.2 — the donation-type badge above renders the literal
                // word "Campaign" for every campaign donation; this column says
                // WHICH campaign.
                //
                // `DonationCampaign::$title` is a localized PHP accessor, NOT a
                // column — sorting/searching it directly would emit SQL against
                // a non-existent `title` column and 500 (same trap as the Home
                // Page Settings incident). So: display via a state closure,
                // sort on the real `title_gu` column (Filament builds a
                // correlated subquery for relationship columns), and search
                // both title columns explicitly.
                Tables\Columns\TextColumn::make('campaign.title_gu')
                    ->label('Campaign')
                    ->getStateUsing(fn (Donation $record): ?string => $record->campaign
                        ? ($record->campaign->title_en ?: $record->campaign->title_gu)
                        : null)
                    ->placeholder('—')
                    ->wrap()
                    ->sortable()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query->whereHas(
                        'campaign',
                        fn (Builder $subQuery): Builder => $subQuery
                            ->where('title_gu', 'like', "%{$search}%")
                            ->orWhere('title_en', 'like', "%{$search}%")
                            ->orWhere('title_hi', 'like', "%{$search}%"),
                    )),
                // Item 5.4 — make Gupt Daan visible in the list. The donor
                // name column to the left still shows WHO donated (details
                // are always retained); this only says the donor CHOSE
                // anonymity at checkout and is masked on public donor
                // lists. It is independent of the 80G column beside it: a
                // PAN-less donor is named, and a Gupt Daan donor with a PAN
                // still gets their receipt.
                Tables\Columns\IconColumn::make('anonymous')
                    ->label('Gupt Daan')
                    ->boolean()
                    ->trueIcon('heroicon-o-eye-slash')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->sortable(),
                Tables\Columns\TextColumn::make('financial_year')->sortable(),
                Tables\Columns\TextColumn::make('created_at')->dateTime('d M Y H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('donation_type')->options([
                    'general' => 'General', 'seva' => 'Seva', 'annadan' => 'Annadan',
                    'construction' => 'Construction', 'festival' => 'Festival', 'campaign' => 'Campaign',
                ]),
                // Item 5.4 — lets staff pull the Gupt Daan register without
                // exporting the whole table.
                Tables\Filters\TernaryFilter::make('anonymous')
                    ->label('Gupt Daan')
                    ->placeholder('All donations')
                    ->trueLabel('Gupt Daan only')
                    ->falseLabel('Named only'),
                Tables\Filters\SelectFilter::make('financial_year')->options(fn () => cache()->remember('donation_financial_years', 3600, fn () => Donation::distinct()->pluck('financial_year', 'financial_year')->toArray())),
                // Captured-only filter, ON by default. Hides
                // pending/created/failed donation rows from the admin
                // list + the column summariser (which used to silently
                // include uncaptured rows in the revenue ₹ total).
                // Operators can toggle this off to debug abandoned
                // donations or audit the full pipeline.
                Tables\Filters\Filter::make('captured_only')
                    ->label('Captured payments only')
                    ->default()
                    ->query(fn (Builder $query): Builder => $query
                        ->whereHas('payment', fn ($q) => $q->where('status', 'captured'))),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
                Tables\Actions\Action::make('view_receipt')
                    ->label('Receipt')
                    ->icon('heroicon-o-document-text')
                    ->color('success')
                    ->url(fn (Donation $record) => $record->receipt?->pdf_path
                        ? route('filament.admin.resources.donations.view', $record)
                        : null)
                    ->visible(fn (Donation $record) => $record->receipt_generated),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDonations::route('/'),
            'view' => Pages\ViewDonation::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Eager-load relations to avoid N+1 on the list/view (devotee.name
        // and campaign.title in the table, receipt.* and payment.* on the
        // view page).
        return parent::getEloquentQuery()->with(['devotee', 'payment', 'receipt', 'campaign']);
    }
}
