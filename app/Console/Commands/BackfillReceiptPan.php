<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Receipt80G;
use App\Services\ReceiptService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

/**
 * Repair 80G receipts issued with a MASKED PAN (2026-08-13).
 *
 * Receipts created before the Aug 9 strict-80G change stored the donor's PAN
 * as "******211R". An 80G receipt is a statutory document the donor files
 * with their return, and a masked PAN makes it useless for that purpose — the
 * assessing officer needs the full number.
 *
 * The current issue path (ReceiptService::generateReceipt) already stores the
 * full PAN, so this is a one-off repair of history, not an ongoing job.
 *
 * The full PAN is recovered from the DEVOTEE's encrypted column, which is the
 * same source the receipt snapshot was taken from. Where that no longer
 * decrypts — the devotee deleted their account, or cleared their PAN — the row
 * is left exactly as it is and reported, because inventing a number on a tax
 * document is far worse than leaving it masked.
 *
 * pdf_path is cleared so the cached PDF is rebuilt from the corrected row on
 * the next download; r2_private is a regenerable cache and every download
 * surface self-heals on a missing path.
 */
class BackfillReceiptPan extends Command
{
    protected $signature = 'receipts:backfill-pan {--dry-run : Report what would change without writing}';

    protected $description = 'Restore the full PAN on 80G receipts that stored a masked one';

    public function handle(ReceiptService $receipts): int
    {
        $dry = (bool) $this->option('dry-run');

        $masked = Receipt80G::with('donation.devotee')
            ->where('pan_number', 'like', '%*%')
            ->orderBy('id')
            ->get();

        if ($masked->isEmpty()) {
            $this->info('No masked PANs found. Nothing to do.');

            return self::SUCCESS;
        }

        $this->info(($dry ? '[DRY RUN] ' : '').'Found '.$masked->count().' receipt(s) with a masked PAN.');

        $fixed = 0;
        $skipped = 0;

        foreach ($masked as $receipt) {
            $devotee = $receipt->donation?->devotee;
            $encrypted = $devotee?->pan_encrypted;

            if (empty($encrypted)) {
                $this->warn("  {$receipt->receipt_number} — no PAN on the devotee any more, left as-is");
                $skipped++;

                continue;
            }

            try {
                $pan = strtoupper(trim(Crypt::decryptString($encrypted)));
            } catch (\Throwable $e) {
                $this->warn("  {$receipt->receipt_number} — PAN could not be decrypted, left as-is");
                Log::warning('BackfillReceiptPan: decrypt failed', [
                    'receipt' => $receipt->receipt_number,
                    'error' => $e->getMessage(),
                ]);
                $skipped++;

                continue;
            }

            // Sanity: the recovered number must end the same way the mask did,
            // or we are about to write a DIFFERENT person's PAN onto a
            // statutory document. Compare the last four visible characters.
            $tail = substr($receipt->pan_number, -4);
            if ($tail !== '' && ! str_ends_with($pan, $tail)) {
                $this->error("  {$receipt->receipt_number} — recovered PAN does not match the masked tail ({$tail}), left as-is");
                $skipped++;

                continue;
            }

            $this->line("  {$receipt->receipt_number} — {$receipt->pan_number} → {$pan}");

            if (! $dry) {
                $receipt->update([
                    'pan_number' => $pan,
                    // Force the cached PDF to be rebuilt from the corrected row.
                    'pdf_path' => null,
                ]);
            }

            $fixed++;
        }

        $this->newLine();
        $this->info(($dry ? 'Would fix' : 'Fixed').": {$fixed}   Skipped: {$skipped}");

        if (! $dry && $fixed > 0) {
            $this->line('Cached PDFs were cleared; each rebuilds with the full PAN on its next download.');
        }

        return self::SUCCESS;
    }
}
