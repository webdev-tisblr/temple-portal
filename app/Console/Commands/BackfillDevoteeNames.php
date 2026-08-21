<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Devotee;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recover the names of devotees whose account was created with none
 * (2026-08-21).
 *
 * OTP signup creates the row with `name => ''` — the phone is verified
 * before there is a name to ask for — and until this batch nothing forced
 * the profile to be finished afterwards. The consequence was not cosmetic:
 * every WhatsApp template binds the devotee's name, and Meta rejects the
 * WHOLE message when a parameter is an empty string, so those accounts
 * received no booking confirmation, no 80G receipt and no greeting card.
 *
 * A name cannot be invented, but for many of these devotees we were told
 * one at some point and wrote it somewhere else. In descending order of
 * how deliberately the devotee typed it:
 *
 *   1. receipt_80g.devotee_name    — a name on a statutory document
 *   2. hall_bookings.contact_name  — typed into the booking form
 *   3. orders.shipping_name        — typed for a delivery
 *   4. seva_bookings.devotee_name_for_seva — may be a RELATIVE's name
 *      (a seva booked in someone else's name), which is why it ranks last
 *
 * (4) is the one that can be wrong, so it is off unless --include-seva is
 * passed. The rest are the account holder by construction.
 *
 * Whatever this cannot recover is left blank on purpose. The app now holds
 * such a devotee on the profile screen until they fill it in, and the
 * admin panel has a "Missing name" filter for the trust to work through.
 *
 * Always rehearse with --dry-run first.
 */
class BackfillDevoteeNames extends Command
{
    protected $signature = 'devotees:backfill-names
        {--dry-run : Report what would change without writing}
        {--include-seva : Also use seva_bookings.devotee_name_for_seva, which may name a relative rather than the account holder}';

    protected $description = 'Fill in missing devotee names from bookings, orders and issued receipts';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $includeSeva = (bool) $this->option('include-seva');

        $nameless = Devotee::whereRaw("COALESCE(TRIM(name), '') = ''")->get();

        $this->info("Devotees with no name: {$nameless->count()}");

        if ($nameless->isEmpty()) {
            return self::SUCCESS;
        }

        $filled = 0;
        $stillBlank = [];

        foreach ($nameless as $devotee) {
            $name = null;
            $from = null;

            // 80G receipts hang off donations, not devotees, so this one
            // needs the join the others don't.
            $receiptName = DB::table('temple_receipts_80g')
                ->join('temple_donations', 'temple_donations.id', '=', 'temple_receipts_80g.donation_id')
                ->where('temple_donations.devotee_id', $devotee->id)
                ->whereRaw("COALESCE(TRIM(temple_receipts_80g.devotee_name), '') <> ''")
                ->orderByDesc('temple_receipts_80g.generated_at')
                ->value('temple_receipts_80g.devotee_name');

            if (filled($receiptName)) {
                $name = $receiptName;
                $from = 'receipt (80G)';
            }

            foreach ([
                ['temple_hall_bookings', 'contact_name', 'hall booking'],
                ['temple_orders', 'shipping_name', 'store order'],
            ] as [$table, $column, $label]) {
                if ($name !== null) {
                    break;
                }
                $candidate = DB::table($table)
                    ->where('devotee_id', $devotee->id)
                    ->whereRaw("COALESCE(TRIM({$column}), '') <> ''")
                    ->orderByDesc('created_at')
                    ->value($column);
                if (filled($candidate)) {
                    $name = $candidate;
                    $from = $label;
                }
            }

            if ($name === null && $includeSeva) {
                $candidate = DB::table('temple_seva_bookings')
                    ->where('devotee_id', $devotee->id)
                    ->whereRaw("COALESCE(TRIM(devotee_name_for_seva), '') <> ''")
                    ->orderByDesc('created_at')
                    ->value('devotee_name_for_seva');
                if (filled($candidate)) {
                    $name = $candidate;
                    $from = 'seva booking (may be a relative)';
                }
            }

            if ($name === null) {
                $stillBlank[] = $devotee->phone;

                continue;
            }

            $name = trim((string) $name);

            $this->line("  {$devotee->phone} → {$name}   [{$from}]");

            if (! $dryRun) {
                $devotee->update(['name' => $name]);
            }
            $filled++;
        }

        $this->newLine();
        $this->info(($dryRun ? '[dry run] would fill' : 'filled').": {$filled}");
        $this->warn('still blank: '.count($stillBlank));

        if ($stillBlank !== []) {
            $this->newLine();
            $this->line('No recorded name anywhere for these numbers. They are held on the');
            $this->line('profile screen the next time they open the app; the trust can also');
            $this->line('reach them from Admin → Devotees → filter "Missing name".');
            foreach (array_slice($stillBlank, 0, 50) as $phone) {
                $this->line("  {$phone}");
            }
            if (count($stillBlank) > 50) {
                $this->line('  … and '.(count($stillBlank) - 50).' more');
            }
        }

        return self::SUCCESS;
    }
}
