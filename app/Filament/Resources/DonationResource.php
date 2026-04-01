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

class DonationResource extends Resource
{
    protected static ?string $model = Donation::class;

    protected static ?string $navigationIcon = 'heroicon-o-gift';

    protected static ?string $navigationGroup = 'Donations & Finance';

    protected static ?int $navigationSort = 1;

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
                Infolists\Components\TextEntry::make('devotee.name')->label('Devotee'),
                Infolists\Components\TextEntry::make('devotee.phone')->label('Phone'),
                Infolists\Components\TextEntry::make('amount')->prefix('₹')->label('Amount'),
                Infolists\Components\TextEntry::make('donation_type')->badge()->label('Type'),
                Infolists\Components\TextEntry::make('purpose')->label('Purpose'),
                Infolists\Components\TextEntry::make('financial_year')->label('Financial Year'),
                Infolists\Components\IconEntry::make('pan_verified')->boolean()->label('PAN Verified'),
                Infolists\Components\IconEntry::make('receipt_generated')->boolean()->label('Receipt Generated'),
                Infolists\Components\TextEntry::make('receipt.receipt_number')->label('Receipt Number'),
                Infolists\Components\TextEntry::make('created_at')->dateTime()->label('Date'),
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
                Tables\Columns\TextColumn::make('financial_year')->sortable(),
                Tables\Columns\IconColumn::make('pan_verified')->boolean(),
                Tables\Columns\IconColumn::make('receipt_generated')->boolean(),
                Tables\Columns\TextColumn::make('created_at')->dateTime('d M Y H:i')->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->filters([
                Tables\Filters\SelectFilter::make('donation_type')->options([
                    'general' => 'General', 'seva' => 'Seva', 'annadan' => 'Annadan',
                    'construction' => 'Construction', 'festival' => 'Festival', 'campaign' => 'Campaign',
                ]),
                Tables\Filters\SelectFilter::make('financial_year')->options(fn () => Donation::distinct()->pluck('financial_year', 'financial_year')->toArray()),
                Tables\Filters\TernaryFilter::make('pan_verified'),
                Tables\Filters\TernaryFilter::make('receipt_generated'),
            ])
            ->actions([
                Tables\Actions\ViewAction::make(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListDonations::route('/'),
            'view' => Pages\ViewDonation::route('/{record}'),
        ];
    }
}
