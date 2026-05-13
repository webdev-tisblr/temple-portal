<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Devotee;
use App\Services\Notifications\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Daily cron — finds devotees with a birthday today and fires the
 * `devotee.birthday` notification trigger for each. Whether anything
 * actually sends is entirely up to which NotificationTemplate rows
 * the admin has created for that trigger (WhatsApp / email / SMS).
 *
 * No hardcoded message bodies, no hardcoded push fan-out — if the
 * admin hasn't configured a template, nobody hears from us.
 */
class SendBirthdayBlessings extends Command
{
    protected $signature = 'temple:send-birthday-blessings';

    protected $description = 'Fire the devotee.birthday trigger for every devotee whose birthday is today.';

    public function handle(NotificationService $notifications): int
    {
        $today = now();
        $month = (int) $today->month;
        $day = (int) $today->day;

        $devotees = Devotee::query()
            ->whereNotNull('date_of_birth')
            ->where('is_active', true)
            ->whereMonth('date_of_birth', $month)
            ->whereDay('date_of_birth', $day)
            ->get();

        $count = $devotees->count();

        if ($count === 0) {
            $this->info('No birthdays today.');
            Log::info('temple:send-birthday-blessings: no birthdays today.');
            return self::SUCCESS;
        }

        $this->info("Found {$count} devotee(s) with birthdays today. Dispatching trigger...");

        foreach ($devotees as $devotee) {
            $notifications->dispatch('devotee.birthday', [
                'devotee' => $devotee,
                'name' => $devotee->name,
                'language' => $devotee->language?->value ?? 'gu',
            ]);
        }

        $this->info("devotee.birthday dispatched for {$count} devotee(s). Channels that fire depend on which templates are enabled in admin.");
        Log::info('temple:send-birthday-blessings: trigger dispatched', [
            'count' => $count,
            'month' => $month,
            'day' => $day,
        ]);

        return self::SUCCESS;
    }
}
