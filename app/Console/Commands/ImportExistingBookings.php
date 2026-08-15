<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ExistingBookingImporter;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * CLI front end for ExistingBookingImporter — the same import the admin
 * "Existing bookings" page runs, for when a sheet is easier to push from a
 * shell than through a browser.
 *
 * All the behaviour (what a blackout is, when a booking is used instead, how
 * rows dedupe) lives in the service, so the two entry points cannot drift.
 */
class ImportExistingBookings extends Command
{
    protected $signature = 'temple:import-bookings
                            {file : Path to the CSV}
                            {--dry-run : Report what would change, change nothing}
                            {--export= : Instead of importing, write the current blocked/booked dates to this path}';

    protected $description = 'Load already-taken dates from a CSV as blackouts or confirmed bookings';

    public function handle(ExistingBookingImporter $importer): int
    {
        if ($export = $this->option('export')) {
            file_put_contents($export, $importer->exportCurrent());
            $this->info("Current blocked/booked dates written to {$export}");

            return self::SUCCESS;
        }

        $path = (string) $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');

        if (! is_readable($path)) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        $rows = $importer->readCsv($path);

        if ($rows === []) {
            $this->error('No data rows found.');

            return self::FAILURE;
        }

        $this->info(($dryRun ? 'DRY RUN — ' : '').count($rows).' row(s) to process.');
        $this->newLine();

        $result = $importer->import($rows, $dryRun);

        foreach ($result['lines'] as $line) {
            $text = sprintf('  line %-4d %-9s %s', $line['line'], $line['status'], $line['message']);
            $line['status'] === 'failed' ? $this->error($text) : $this->line($text);
        }

        $stats = $result['stats'];

        $this->newLine();
        $this->info(sprintf(
            'Dates blocked: %d   Bookings created: %d   Already present: %d   Failed: %d',
            $stats['blocked'], $stats['booked'], $stats['already'], $stats['failed'],
        ));

        if (! $dryRun) {
            Log::info('temple:import-bookings', $stats + ['file' => basename($path)]);
        }

        return $stats['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
