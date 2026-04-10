<?php

declare(strict_types=1);

namespace App\Filament\Resources\DonationResource\Pages;

use App\Filament\Resources\DonationResource;
use App\Models\Donation;
use Barryvdh\DomPDF\Facade\Pdf;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Collection;

class ListDonations extends ListRecords
{
    protected static string $resource = DonationResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Actions\Action::make('export')
                ->label('Export')
                ->icon('heroicon-o-arrow-down-tray')
                ->form([
                    Forms\Components\DatePicker::make('date_from')
                        ->label('From')
                        ->default(now()->startOfMonth()->toDateString())
                        ->required(),
                    Forms\Components\DatePicker::make('date_to')
                        ->label('To')
                        ->default(now()->toDateString())
                        ->required(),
                    Forms\Components\Select::make('format')
                        ->label('File Type')
                        ->options(['csv' => 'CSV', 'pdf' => 'PDF'])
                        ->default('csv')
                        ->required(),
                ])
                ->action(function (array $data) {
                    $donations = Donation::with('devotee', 'receipt', 'payment')
                        ->whereDate('created_at', '>=', $data['date_from'])
                        ->whereDate('created_at', '<=', $data['date_to'])
                        ->orderBy('created_at', 'desc')
                        ->get();

                    return $data['format'] === 'pdf'
                        ? $this->downloadPdf($donations, $data)
                        : $this->downloadCsv($donations, $data);
                }),
        ];
    }

    private function downloadCsv(Collection $donations, array $data): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $filename = "donations_{$data['date_from']}_to_{$data['date_to']}.csv";

        return response()->streamDownload(function () use ($donations) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, ['Date', 'Receipt No.', 'Devotee', 'Phone', 'Amount (₹)', 'Type', 'Purpose', 'Financial Year', 'Payment Status']);

            foreach ($donations as $d) {
                fputcsv($handle, [
                    $d->created_at->format('d/m/Y'),
                    $d->receipt?->receipt_number ?? '-',
                    $d->devotee?->name ?? 'Anonymous',
                    $d->devotee?->phone ?? '-',
                    number_format((float) $d->amount, 2),
                    ucfirst($d->getRawOriginal('donation_type')),
                    $d->purpose ?? '-',
                    $d->financial_year,
                    $d->payment?->status?->value ?? '-',
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    private function downloadPdf(Collection $donations, array $data): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $total = $donations->sum('amount');
        $filename = "donations_{$data['date_from']}_to_{$data['date_to']}.pdf";

        $pdf = Pdf::loadView('exports.donations-pdf', [
            'donations' => $donations,
            'dateFrom' => $data['date_from'],
            'dateTo' => $data['date_to'],
            'total' => $total,
        ])->setPaper('a4', 'landscape');

        $output = $pdf->output();

        return response()->streamDownload(fn () => print($output), $filename, ['Content-Type' => 'application/pdf']);
    }
}
