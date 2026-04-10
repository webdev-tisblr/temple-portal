<?php

declare(strict_types=1);

namespace App\Filament\Exports;

use App\Models\Donation;
use Filament\Actions\Exports\ExportColumn;
use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;

class DonationExporter extends Exporter
{
    protected static ?string $model = Donation::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('Donation ID'),
            ExportColumn::make('created_at')->label('Date'),
            ExportColumn::make('devotee.name')->label('Devotee Name'),
            ExportColumn::make('devotee.phone')->label('Phone'),
            ExportColumn::make('amount')->label('Amount (₹)'),
            ExportColumn::make('donation_type')->label('Type')
                ->formatStateUsing(fn ($state) => ucfirst($state->value ?? $state ?? '')),
            ExportColumn::make('purpose')->label('Purpose'),
            ExportColumn::make('financial_year')->label('Financial Year'),
            ExportColumn::make('receipt.receipt_number')->label('Receipt No.'),
            ExportColumn::make('pan_verified')->label('PAN Verified')
                ->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No'),
            ExportColumn::make('anonymous')->label('Anonymous')
                ->formatStateUsing(fn ($state) => $state ? 'Yes' : 'No'),
            ExportColumn::make('payment.status')->label('Payment Status'),
        ];
    }

    public static function getCompletedNotificationBody(Export $export): string
    {
        $count = number_format($export->successful_rows);

        return "Donation export completed — {$count} rows exported.";
    }
}
