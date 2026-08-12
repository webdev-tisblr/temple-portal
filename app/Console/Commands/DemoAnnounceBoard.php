<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DonationBoardEntry;
use App\Services\DisplayBoardService;
use Illuminate\Console\Command;

/**
 * Put a fake offering on the display board so the trust can rehearse the
 * screen — position, type size, legibility from the back of the hall — without
 * touching the money path or creating a real donation.
 *
 * The real end-to-end rehearsal is a ₹11 cash entry through the admin counter,
 * which exercises the actual capture path. This command is for the visual pass,
 * where you want twenty announcements in a row and no receipts, no WhatsApp
 * messages, and no rows in the donations table.
 */
class DemoAnnounceBoard extends Command
{
    protected $signature = 'board:demo-announce
                            {--name= : Donor name to show}
                            {--amount=11000 : Amount in rupees}
                            {--city=Gandhidham : City}
                            {--count=1 : How many to queue}
                            {--clear : Suppress every demo entry instead of adding one}';

    protected $description = 'Put a demo offering on the live donor display board (creates no donation)';

    /**
     * Plausible types for rehearsal rows.
     *
     * Demo rows are identified by `donation_id IS NULL`, never by a marker
     * string — an earlier version put '__demo__' in the donation_type field
     * and it rendered on screen as the offering's category.
     */
    private const DEMO_TYPES = ['સામાન્ય દાન', 'અન્નદાન સેવા', 'ધ્વજા સેવા', 'વસ્ત્ર સેવા'];

    public function handle(DisplayBoardService $board): int
    {
        if ($this->option('clear')) {
            $cleared = DonationBoardEntry::whereNull('donation_id')
                ->whereNull('suppressed_at')
                ->update(['suppressed_at' => now()]);

            $this->info("Suppressed {$cleared} demo entrie(s). Real donations untouched.");

            return self::SUCCESS;
        }

        if (! $board->enabled()) {
            $this->warn('The board is switched OFF (board_enabled = 0), so nothing will appear on screen.');
            $this->line('Turn it on in Admin → Settings → Live Donor Display Board.');
        }

        $names = ['રમેશભાઈ પટેલ', 'Kiran Shah', 'सुनीता देवी', 'જયેશ ઠક્કર', 'Meera Joshi'];
        $amounts = [1100, 5100, 11000, 21000, 251000];
        $count = max(1, (int) $this->option('count'));

        for ($i = 0; $i < $count; $i++) {
            $now = now();

            DonationBoardEntry::create([
                // NULL donation_id is what marks a row as synthetic — a real
                // announcement always has one, and the column is unique so
                // demo rows can never collide with a genuine gift.
                'donation_id' => null,
                'payload' => [
                    'name' => $this->option('name') ?: $names[$i % count($names)],
                    'city' => (string) $this->option('city'),
                    'amount' => (float) ($this->option('name')
                        ? $this->option('amount')
                        : $amounts[$i % count($amounts)]),
                    'anonymous' => false,
                    // Every third row shows a campaign instead of a type, so
                    // the rehearsal exercises both row shapes.
                    'campaign_title' => $i % 3 === 2 ? 'શ્રી રામ વાટિકા' : null,
                    'donation_type' => self::DEMO_TYPES[$i % count(self::DEMO_TYPES)],
                ],
                'announced_at' => $now,
                'visible_from' => $now->copy()->addSeconds($board->delaySeconds()),
                'anonymous' => false,
            ]);
        }

        $this->info("Queued {$count} demo announcement(s). They appear on screen in ~{$board->delaySeconds()}s.");
        $this->line('Remove them afterwards with: php artisan board:demo-announce --clear');

        return self::SUCCESS;
    }
}
