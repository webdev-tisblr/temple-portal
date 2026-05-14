<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Receipt80G;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Sweep cached 80G receipt PDFs from R2 private storage.
 *
 * Why retention is short by default:
 *   • Every receipt is fully reproducible from its Receipt80G row +
 *     SystemSetting trust metadata via ReceiptService::generatePdf().
 *   • ReceiptService::generateReceipt() already re-generates the PDF
 *     when the file is missing, so DashboardController + API both
 *     transparently rebuild and stream on demand (~1s DomPDF render).
 *   • R2 charges per GB-month — keeping every receipt forever scales
 *     storage linearly with donation count for zero functional benefit.
 *
 * Default retention is 7 days. Pass --days=N to override.
 *
 * What this command does NOT do:
 *   • Delete Receipt80G rows. Those are accounting records — they live
 *     forever. Only the cached PDF file gets swept.
 *   • Touch user-uploaded files (profile photos, donation extras).
 *
 * Scheduled in routes/console.php — runs nightly so devotees who
 * downloaded today still hit cache; those who need an old receipt pay
 * the ~1s regenerate cost once.
 */
class CleanGeneratedReceipts extends Command
{
    protected $signature = 'receipts:clean-generated {--days=7 : Cached PDFs older than this are deleted (regenerated on next download)}';

    protected $description = 'Delete cached 80G receipt PDFs older than the retention window — regenerated on demand from DB';

    public function handle(): int
    {
        $days = (int) $this->option('days');
        if ($days < 1) {
            $this->error('--days must be >= 1');
            return self::FAILURE;
        }

        $disk = Storage::disk('r2_private');
        $cutoff = now()->subDays($days)->timestamp;
        $deleted = 0;
        $clearedColumns = 0;

        $this->info("Sweeping 80G receipt PDFs older than {$days} days…");

        // R2 has no directories — listFiles walks every key prefixed
        // with "receipts/" regardless of FY subfolder. Chunking via
        // generator-style iteration keeps memory bounded even with
        // tens of thousands of receipts.
        foreach ($disk->allFiles('receipts') as $path) {
            try {
                if ($disk->lastModified($path) < $cutoff) {
                    $disk->delete($path);
                    $deleted++;

                    // Null the pdf_path on the Receipt80G row so the next
                    // download attempt cleanly triggers regenerate-if-missing
                    // rather than racing on a stale path that no longer
                    // resolves. The Receipt80G row itself stays — it's the
                    // accounting record.
                    $clearedColumns += Receipt80G::where('pdf_path', $path)
                        ->update(['pdf_path' => null]);
                }
            } catch (\Throwable $e) {
                Log::warning('CleanGeneratedReceipts: sweep failed for path', [
                    'path' => $path,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $this->info("Deleted {$deleted} cached receipt PDF(s); cleared {$clearedColumns} pdf_path columns.");
        Log::info('CleanGeneratedReceipts ran', [
            'days' => $days,
            'deleted' => $deleted,
            'columns_cleared' => $clearedColumns,
        ]);

        return self::SUCCESS;
    }
}
