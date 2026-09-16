<?php

declare(strict_types=1);

namespace App\Filament\Resources\SevaBookingResource\Pages;

use App\Filament\Resources\SevaBookingResource;
use App\Models\SevaBooking;
use App\Models\SystemSetting;
use App\Support\Pdf\GujaratiPdf;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Filters\Indicator;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ListSevaBookings extends ListRecords
{
    protected static string $resource = SevaBookingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Exports EXACTLY what the table is showing (2026-09-16): the
            // same filters, search and sort the admin has applied — not a
            // separate date-range dialog like the donations export. The
            // point is "I have narrowed the list to tomorrow's Sundarkand
            // bookings, give me that as a sheet", so the export must never
            // silently widen or narrow what is on screen.
            Actions\Action::make('export')
                ->label('Export')
                ->icon('heroicon-o-arrow-down-tray')
                // Same treatment as ListDonations::export (G10): this
                // dumps every devotee's name + phone. `export_seva_bookings`
                // is seeded in RolePermissionSeeder::CUSTOM_PERMISSIONS.
                ->visible(fn (): bool => auth('admin')->user()?->can('export_seva_bookings') ?? false)
                ->modalHeading('Export seva bookings')
                ->modalDescription(fn (): string => $this->exportScopeDescription())
                ->modalSubmitActionLabel('Download')
                ->form([
                    Forms\Components\Select::make('format')
                        ->label('File type')
                        ->options(['csv' => 'CSV (Excel)', 'pdf' => 'PDF'])
                        ->default('csv')
                        ->required(),
                ])
                ->action(function (array $data): StreamedResponse {
                    $bookings = $this->exportQuery()->get();

                    return $data['format'] === 'pdf'
                        ? $this->downloadPdf($bookings)
                        : $this->downloadCsv($bookings);
                }),
        ];
    }

    /**
     * The table's own query — filters, search and column sort included —
     * so the file matches the screen row for row.
     *
     * Eager loads are re-stated because getFilteredSortedTableQuery()
     * starts from the resource query (which already carries them) but a
     * defensive ->with() here costs nothing and keeps the export from
     * lazy-loading five relations per row if the resource ever changes.
     */
    private function exportQuery(): Builder
    {
        return $this->getFilteredSortedTableQuery()
            ->with(['seva', 'devotee', 'payment', 'selectedProduct', 'receipt80G']);
    }

    /**
     * Human-readable list of the filters in force, e.g.
     * "Paid bookings only · Timeframe: Upcoming (today onwards) · Seva: Sundarkand".
     *
     * Read from the filters' own indicators rather than the raw state so
     * the wording matches the chips above the table exactly. An export
     * that does not say what it was filtered to reads as the full list to
     * whoever receives it.
     *
     * @return list<string>
     */
    private function activeFilterLabels(): array
    {
        $labels = [];

        foreach ($this->getTable()->getFilters() as $filter) {
            foreach ($filter->getIndicators() as $indicator) {
                $label = $indicator instanceof Indicator ? $indicator->getLabel() : $indicator;
                $label = $label instanceof Htmlable ? strip_tags($label->toHtml()) : (string) $label;

                if (trim($label) !== '') {
                    $labels[] = trim($label);
                }
            }
        }

        $search = $this->getTableSearch();
        if ($search !== null && $search !== '') {
            $labels[] = 'Search: '.$search;
        }

        return $labels;
    }

    private function exportScopeDescription(): string
    {
        $labels = $this->activeFilterLabels();
        $count = $this->exportQuery()->count();

        $scope = $labels === []
            ? 'No filters applied — every booking will be exported.'
            : 'Filters applied: '.implode(' · ', $labels).'.';

        return $scope.' '.number_format($count).' booking(s) match.';
    }

    private function exportFilename(string $extension): string
    {
        $timeframe = (string) ($this->tableFilters['timeframe']['value'] ?? 'upcoming');

        return 'seva-bookings-'.$timeframe.'-'.now()->format('Y-m-d-Hi').'.'.$extension;
    }

    /** @param Collection<int, SevaBooking> $bookings */
    private function downloadCsv(Collection $bookings): StreamedResponse
    {
        $filterLine = $this->activeFilterLabels();

        return response()->streamDownload(function () use ($bookings, $filterLine) {
            $handle = fopen('php://output', 'w');

            // UTF-8 BOM so Excel on Windows opens Gujarati names and the
            // rupee sign correctly instead of as mojibake.
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'Booking Date', 'Slot', 'Seva', 'Devotee', 'Phone', 'Name for Seva',
                'Product', 'Variant', 'Qty', 'Amount (₹)', 'Booking Status',
                'Payment Status', 'Payment Mode', 'Razorpay Payment ID',
                'Receipt No.', '80G', 'Booked At', 'Cancellation Reason', 'Notes',
            ]);

            foreach ($bookings as $b) {
                fputcsv($handle, self::row($b));
            }

            // Trailer, same idea as the PDF header: the sheet says what it
            // is, so a forwarded copy cannot be mistaken for the whole list.
            fputcsv($handle, []);
            fputcsv($handle, ['Exported', now()->format('d/m/Y H:i')]);
            fputcsv($handle, ['Filters', $filterLine === [] ? 'None (all bookings)' : implode(' | ', $filterLine)]);
            fputcsv($handle, ['Bookings', (string) $bookings->count()]);
            fputcsv($handle, ['Total (₹)', number_format((float) $bookings->sum('total_amount'), 2, '.', '')]);

            fclose($handle);
        }, $this->exportFilename('csv'), ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @param Collection<int, SevaBooking> $bookings */
    private function downloadPdf(Collection $bookings): StreamedResponse
    {
        // GujaratiPdf (mPDF), not DomPDF: seva names, devotee names and
        // "name for seva" are routinely Gujarati, which DomPDF cannot shape.
        $bytes = GujaratiPdf::render('exports.seva-bookings-pdf', [
            'bookings' => $bookings,
            'filters' => $this->activeFilterLabels(),
            'trustName' => SystemSetting::getValue('trust_name_en', 'Shree Patadiya Hanumanji Seva Trust'),
            'total' => (float) $bookings->sum('total_amount'),
            'exportedBy' => auth('admin')->user()?->name ?? '—',
        ], ['format' => 'A4-L']);

        return response()->streamDownload(
            fn () => print ($bytes),
            $this->exportFilename('pdf'),
            ['Content-Type' => 'application/pdf'],
        );
    }

    /**
     * One flat row per booking, shared by CSV and (via the view) PDF.
     *
     * @return list<string>
     */
    public static function row(SevaBooking $b): array
    {
        $status = $b->status instanceof \BackedEnum ? $b->status->value : (string) $b->status;
        $paymentStatus = $b->payment?->status;
        $paymentStatus = $paymentStatus instanceof \BackedEnum ? $paymentStatus->value : (string) ($paymentStatus ?? '');
        $offline = $b->payment?->isOffline() ?? false;

        return [
            $b->booking_date?->format('d/m/Y') ?? '-',
            // slot_time_label, never slot_time: full-day/full-week store a
            // sentinel there, not a clock time.
            $b->slot_time_label ?? '-',
            $b->seva?->name_en ?: ($b->seva?->name_gu ?? '-'),
            $b->devotee?->name ?? '-',
            $b->devotee?->phone ?? '-',
            $b->devotee_name_for_seva ?? '-',
            $b->selectedProduct?->name_en ?: ($b->selectedProduct?->name_gu ?? '-'),
            $b->selected_variant_label ?? '-',
            (string) ($b->quantity ?? 1),
            number_format((float) $b->total_amount, 2, '.', ''),
            ucfirst($status),
            $paymentStatus !== '' ? ucfirst($paymentStatus) : '-',
            $b->payment ? ($offline ? ($b->payment->method ?? 'offline') : 'online') : '-',
            $b->payment?->razorpay_payment_id ?? '-',
            // Statutory 80G number when one was issued, else the plain
            // seva receipt number (display_receipt_number does exactly
            // that, and receipt80G is eager-loaded so it does not lazy-load).
            $b->display_receipt_number ?? '-',
            $b->receipt80G !== null ? 'Yes' : ($b->wants_80g ? 'Asked, not issued' : 'No'),
            $b->created_at?->format('d/m/Y H:i') ?? '-',
            $b->cancellation_reason ?? '',
            $b->notes ?? '',
        ];
    }
}
