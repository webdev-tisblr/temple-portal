<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HallBooking;
use App\Models\Order;
use App\Models\SevaBooking;
use Illuminate\Console\Command;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Sweep cached store + hall booking invoice PDFs from R2 private
 * storage. Same regenerate-on-demand model as
 * {@see CleanGeneratedReceipts} — see that command's PHPDoc for the
 * full rationale.
 *
 * Both invoice classes are fully reproducible from their DB rows:
 *   • Store invoices: Order + OrderItem + Devotee + Payment →
 *     InvoiceService::generateInvoice() (~1s DomPDF render).
 *   • Hall invoices: HallBooking + Hall + Devotee + Payment →
 *     GenerateHallInvoice job (run synchronously for self-heal).
 *
 * Web + API download controllers (StoreWebController::downloadInvoice,
 * HallBookingController::downloadInvoice, plus their /api/v1
 * counterparts) all already call exists() + regenerate inline if
 * missing. So deleting cached PDFs is transparent to the devotee.
 *
 * 7-day default matches the 80G receipt window. Both PDF classes are
 * cheap to regenerate (~1s DomPDF), so longer caching only burns
 * storage without UX benefit. Operator support requests beyond 7 days
 * (refund queries, audit copies) trigger a single one-off regenerate
 * on download.
 */
class CleanGeneratedInvoices extends Command
{
    protected $signature = 'invoices:clean-generated {--days=7 : Cached PDFs older than this are deleted (regenerated on next download)}';

    protected $description = 'Delete cached store + hall invoice PDFs older than retention — regenerated on demand from DB';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days < 1) {
            $this->error('--days must be >= 1');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days)->timestamp;
        $disk = Storage::disk('r2_private');
        $storeDeleted = $this->sweepDirectory($disk, 'invoices', $cutoff, Order::class, 'invoice_path');
        $hallDeleted = $this->sweepDirectory($disk, 'hall-invoices', $cutoff, HallBooking::class, 'invoice_path');
        // Seva receipts follow the same regenerate-on-download model
        // (SevaReceiptService rebuilds on a NULL receipt_path).
        $sevaDeleted = $this->sweepDirectory($disk, 'seva-receipts', $cutoff, SevaBooking::class, 'receipt_path');

        $this->info("Deleted {$storeDeleted} store invoice(s), {$hallDeleted} hall invoice(s) and {$sevaDeleted} seva receipt(s).");
        Log::info('CleanGeneratedInvoices ran', [
            'days' => $days,
            'store_deleted' => $storeDeleted,
            'hall_deleted' => $hallDeleted,
            'seva_deleted' => $sevaDeleted,
        ]);

        return self::SUCCESS;
    }

    /**
     * @param  class-string<Model>  $modelClass
     */
    private function sweepDirectory(
        Filesystem $disk,
        string $prefix,
        int $cutoff,
        string $modelClass,
        string $pathColumn,
    ): int {
        $deleted = 0;

        foreach ($disk->allFiles($prefix) as $path) {
            try {
                if ($disk->lastModified($path) < $cutoff) {
                    $disk->delete($path);
                    $deleted++;
                    // Null the DB column so the next download cleanly
                    // triggers regenerate-if-missing instead of trying to
                    // serve a non-existent key. Order / HallBooking rows
                    // themselves are accounting records; only the cached
                    // PDF file is reproducible-and-sweepable.
                    $modelClass::where($pathColumn, $path)->update([$pathColumn => null]);
                }
            } catch (\Throwable $e) {
                Log::warning('CleanGeneratedInvoices: sweep failed for path', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $deleted;
    }
}
