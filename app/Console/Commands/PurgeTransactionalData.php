<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Clear the trust's TRANSACTIONAL data, keeping every piece of configuration.
 *
 * Written for the launch cut-over (2026-08-15): the site had months of test
 * donations, bookings and messages on it and had to open to real devotees
 * with clean books.
 *
 * The line it draws: anything a devotee or the counter CREATED goes; anything
 * the trust CONFIGURED stays. So sevas, halls, donation types, greeting-card
 * artwork and positions, notification templates, reminder rules, CMS pages and
 * app string overrides are all untouched — only the records of activity go.
 *
 * Order is not cosmetic. temple_devotees and temple_donations are referenced
 * ON DELETE NO ACTION, so children must be deleted before their parents or
 * MySQL refuses the whole statement. The list below is in dependency order and
 * runs inside one transaction: it either all works or none of it does.
 *
 * Deliberately NOT a migration or a seeder. A migration would re-run on any
 * fresh environment and silently empty it; a seeder is for creating data, and
 * this repo's deploy explicitly runs no content seeder. This is a one-time
 * operator action that must be typed on purpose.
 */
class PurgeTransactionalData extends Command
{
    protected $signature = 'temple:purge-transactional
                            {--dry-run : Count what would be deleted, delete nothing}
                            {--force : Skip the confirmation prompt (for non-interactive runs)}';

    protected $description = 'Delete transactional records (bookings, donations, devotees, messages) while keeping all configuration';

    /**
     * Child-before-parent. Every entry is deleted in full; the comment says
     * why it is in scope, because "why was this table emptied" is the
     * question anyone reading this later will have.
     */
    private const TABLES = [
        // Messaging history — logs, queued sends, and the devotee-facing inbox.
        'temple_notification_reads',
        'temple_notification_outbox',
        'temple_notification_logs',
        'temple_notifications',
        'temple_whatsapp_webhook_events',

        // Website enquiries.
        'temple_contact_submissions',

        // Darshan photos uploaded during testing.
        'temple_daily_darshan_photos',

        // Donation-derived records BEFORE donations (FK: NO ACTION).
        'temple_receipts_80g',

        // Reminder schedules before the bookings they point at.
        'temple_seva_reminder_schedules',
        'temple_hall_reminder_schedules',

        // The bookings and orders themselves.
        'temple_order_items',
        'temple_orders',
        'temple_seva_bookings',
        'temple_hall_bookings',
        'temple_donations',

        // Payment records, and the webhook events that confirmed them.
        'temple_razorpay_webhook_events',
        'temple_payments',

        // Devotee-owned rows, then the devotees (FK: NO ACTION from the above).
        'temple_web_login_tokens',
        'temple_device_tokens',
        'temple_devotees',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $counts = [];
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                $this->warn("  (skipping {$table} — no such table)");

                continue;
            }
            $counts[$table] = DB::table($table)->count();
        }

        $total = array_sum($counts);

        $this->newLine();
        $this->line($dryRun ? 'DRY RUN — nothing will be deleted.' : 'This will PERMANENTLY delete:');
        $this->table(
            ['Table', 'Rows'],
            collect($counts)->map(fn (int $n, string $t): array => [$t, $n])->values()->all(),
        );
        $this->line("Total: {$total} rows across ".count($counts).' tables.');
        $this->newLine();
        $this->info('KEPT: sevas, halls, donation types, greeting-card configuration, notification');
        $this->info('      templates, reminder rules, CMS content, settings, app string overrides,');
        $this->info('      admin users and roles.');
        $this->newLine();

        if ($dryRun) {
            return self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm('Delete all of the above? There is no undo short of restoring a backup.')) {
            $this->warn('Aborted — nothing was deleted.');

            return self::SUCCESS;
        }

        $deleted = [];

        DB::transaction(function () use ($counts, &$deleted): void {
            // Children are listed before parents, but a stray FK on a table
            // this command does not know about would still block the run —
            // and leaving the database half-emptied is worse than failing.
            // The transaction makes it all-or-nothing; the check stays off
            // only for the duration of this one statement block.
            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            try {
                foreach (array_keys($counts) as $table) {
                    $deleted[$table] = DB::table($table)->delete();
                }

                // Receipt numbers are allocated from a persistent counter. Left
                // alone, the first REAL 80G receipt would carry on from the test
                // data's serial — a statutory document with a gap in front of it.
                if (Schema::hasTable('temple_receipt_sequences')) {
                    DB::table('temple_receipt_sequences')->delete();
                }
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        });

        $this->newLine();
        $this->info('Deleted:');
        foreach ($deleted as $table => $n) {
            $this->line(sprintf('  %-40s %d', $table, $n));
        }
        $this->info('Receipt number sequence reset — the next real receipt starts from the beginning.');

        Log::warning('temple:purge-transactional ran', [
            'deleted' => $deleted,
            'total' => array_sum($deleted),
        ]);

        return self::SUCCESS;
    }
}
