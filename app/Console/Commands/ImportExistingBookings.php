<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Enums\BookingStatus;
use App\Models\Devotee;
use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\Seva;
use App\Models\SevaBooking;
use App\Models\SevaSlotPool;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Load bookings that were taken OFF the platform — on paper, over the phone —
 * so the website stops offering dates that are already spoken for.
 *
 * Written for the 2026-08-15 launch, when the trust had a season of existing
 * bookings and hours to get them in before the site opened. Kept as a command
 * rather than a one-off script because the same sheet gets re-submitted with
 * fuller member details later, and every row is idempotent.
 *
 * TWO WAYS to block a date, and the choice is not arbitrary:
 *
 *   blackout — writes the date into the seva/pool/hall's own blackout list.
 *     Blocks a WHOLE DAY and invents no money. Right when the date is taken
 *     but the member and amount are not yet known: a booking row would put
 *     revenue in the books that nobody has received.
 *
 *   booking — a real confirmed booking row. The only thing that works at SLOT
 *     level, so a Dhwaja slot at 11:00 can be taken while 08:00 and 16:00 stay
 *     open. Also the only one that becomes real history.
 *
 * A blackout is the safe default; convert to a booking once the sheet comes
 * back with who booked it and what they paid.
 *
 * CSV columns:
 *   kind          blackout | seva | hall
 *   target_id     pool:1 / seva:2 / hall:2 for blackout; the seva or hall id otherwise
 *   target_name   ignored — a human label so the sheet is readable
 *   date          YYYY-MM-DD
 *   end_date      hall multi-day only; blank = single day
 *   slot          HH:MM for a time-slot seva; blank = full day
 *   member_name   blank on a blackout; fills the booking's devotee otherwise
 *   member_phone  matched against existing devotees before creating one
 *   amount        blank falls back to the seva/hall list price
 *   notes         free text, stored as the blackout reason / booking note
 */
class ImportExistingBookings extends Command
{
    protected $signature = 'temple:import-bookings
                            {file : Path to the CSV}
                            {--dry-run : Report what would change, change nothing}';

    protected $description = 'Load already-taken dates from a CSV as blackouts or confirmed bookings';

    /** Devotee the placeholder bookings hang off until real names arrive. */
    private const PLACEHOLDER_PHONE = '0000000000';

    public function handle(): int
    {
        $path = (string) $this->argument('file');
        $dryRun = (bool) $this->option('dry-run');

        if (! is_readable($path)) {
            $this->error("Cannot read {$path}");

            return self::FAILURE;
        }

        $rows = $this->readCsv($path);
        if ($rows === []) {
            $this->error('No data rows found.');

            return self::FAILURE;
        }

        $this->info(($dryRun ? 'DRY RUN — ' : '').count($rows).' row(s) to process.');
        $this->newLine();

        $stats = ['blocked' => 0, 'booked' => 0, 'already' => 0, 'failed' => 0];

        foreach ($rows as $i => $row) {
            $line = $i + 2; // +1 for the header, +1 for 1-based counting
            try {
                $result = match (strtolower(trim((string) ($row['kind'] ?? '')))) {
                    'blackout' => $this->applyBlackout($row, $dryRun),
                    'seva' => $this->applySevaBooking($row, $dryRun),
                    'hall' => $this->applyHallBooking($row, $dryRun),
                    default => throw new \RuntimeException("unknown kind '{$row['kind']}'"),
                };

                $stats[$result['status']]++;
                $this->line(sprintf('  line %-4d %-9s %s', $line, $result['status'], $result['message']));
            } catch (\Throwable $e) {
                $stats['failed']++;
                $this->error(sprintf('  line %-4d FAILED   %s', $line, $e->getMessage()));
            }
        }

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

    /**
     * Add one date to a seva's, a pool's or a hall's blackout list.
     *
     * Idempotent by date: re-running never stacks duplicates, and a date the
     * trust later frees up can simply be removed from the sheet — this only
     * ever adds, so removal stays a deliberate admin action.
     *
     * @return array{status: string, message: string}
     */
    private function applyBlackout(array $row, bool $dryRun): array
    {
        $date = $this->date($row['date'] ?? '');
        $reason = trim((string) ($row['notes'] ?? '')) ?: 'Already booked';
        [$type, $id] = $this->target($row['target_id'] ?? '');

        /** @var Seva|SevaSlotPool|Hall $owner */
        $owner = match ($type) {
            'pool' => SevaSlotPool::findOrFail($id),
            'seva' => Seva::findOrFail($id),
            'hall' => Hall::findOrFail($id),
            default => throw new \RuntimeException('blackout target must be pool:N, seva:N or hall:N'),
        };

        // Halls keep blackout_dates in their own column; sevas and pools keep
        // it inside the slot_config JSON.
        if ($owner instanceof Hall) {
            $existing = (array) ($owner->blackout_dates ?? []);
            if ($this->hasDate($existing, $date)) {
                return ['status' => 'already', 'message' => "hall {$id} {$date} already blocked"];
            }
            $existing[] = ['date' => $date, 'reason' => $reason];
            if (! $dryRun) {
                $owner->update(['blackout_dates' => array_values($existing)]);
            }

            return ['status' => 'blocked', 'message' => "hall {$id} {$date} blocked"];
        }

        $config = (array) ($owner->slot_config ?? []);
        $existing = (array) ($config['blackout_dates'] ?? []);
        if ($this->hasDate($existing, $date)) {
            return ['status' => 'already', 'message' => "{$type} {$id} {$date} already blocked"];
        }

        $existing[] = ['date' => $date, 'reason' => $reason];
        $config['blackout_dates'] = array_values($existing);

        if (! $dryRun) {
            $owner->update(['slot_config' => $config]);
        }

        return ['status' => 'blocked', 'message' => "{$type} {$id} {$date} blocked"];
    }

    /**
     * A confirmed seva booking, which is what holds a single SLOT.
     *
     * @return array{status: string, message: string}
     */
    private function applySevaBooking(array $row, bool $dryRun): array
    {
        $seva = Seva::findOrFail((int) $row['target_id']);
        $date = $this->date($row['date'] ?? '');
        $slot = trim((string) ($row['slot'] ?? ''));
        $slotTime = $slot !== '' ? $this->time($slot) : 'full_day';

        $existing = SevaBooking::where('seva_id', $seva->id)
            ->where('booking_date', $date)
            ->where('slot_time', $slotTime)
            ->whereIn('status', ['pending', 'confirmed', 'completed'])
            ->first();

        if ($existing) {
            return ['status' => 'already', 'message' => "seva {$seva->id} {$date} {$slotTime} already booked"];
        }

        $amount = $row['amount'] !== '' && $row['amount'] !== null
            ? (float) $row['amount']
            : (float) $seva->price;

        if ($dryRun) {
            return ['status' => 'booked', 'message' => "would book seva {$seva->id} {$date} {$slotTime} (Rs {$amount})"];
        }

        $devotee = $this->devoteeFor($row);

        DB::transaction(function () use ($seva, $devotee, $date, $slotTime, $amount, $row): void {
            SevaBooking::create([
                'devotee_id' => $devotee->id,
                'seva_id' => $seva->id,
                'booking_date' => $date,
                'slot_time' => $slotTime,
                'quantity' => 1,
                'total_amount' => $amount,
                'status' => BookingStatus::CONFIRMED->value,
                'devotee_name_for_seva' => trim((string) ($row['member_name'] ?? '')) ?: null,
                'notes' => trim((string) ($row['notes'] ?? '')) ?: null,
            ]);
        });

        return ['status' => 'booked', 'message' => "seva {$seva->id} {$date} {$slotTime} booked"];
    }

    /**
     * @return array{status: string, message: string}
     */
    private function applyHallBooking(array $row, bool $dryRun): array
    {
        $hall = Hall::findOrFail((int) $row['target_id']);
        $date = $this->date($row['date'] ?? '');
        $endDate = trim((string) ($row['end_date'] ?? '')) !== ''
            ? $this->date($row['end_date'])
            : $date;

        // Any overlap counts as taken — a hall cannot be double-let even
        // partially, unlike a seva slot.
        $clash = HallBooking::where('hall_id', $hall->id)
            ->whereIn('status', ['pending', 'confirmed', 'completed'])
            ->where('booking_date', '<=', $endDate)
            ->where('end_date', '>=', $date)
            ->first();

        if ($clash) {
            return ['status' => 'already', 'message' => "hall {$hall->id} {$date}..{$endDate} already booked"];
        }

        $days = max(1, (int) Carbon::parse($date)->diffInDays($endDate) + 1);
        $amount = $row['amount'] !== '' && $row['amount'] !== null
            ? (float) $row['amount']
            : (float) $hall->price_per_day * $days;

        if ($dryRun) {
            return ['status' => 'booked', 'message' => "would book hall {$hall->id} {$date}..{$endDate} ({$days}d, Rs {$amount})"];
        }

        $devotee = $this->devoteeFor($row);

        DB::transaction(function () use ($hall, $devotee, $date, $endDate, $days, $amount, $row): void {
            HallBooking::create([
                'devotee_id' => $devotee->id,
                'hall_id' => $hall->id,
                'booking_date' => $date,
                'end_date' => $endDate,
                'days_count' => $days,
                'booking_type' => 'full_day',
                'purpose' => trim((string) ($row['notes'] ?? '')) ?: 'Booked before launch',
                'contact_name' => trim((string) ($row['member_name'] ?? '')) ?: $devotee->name,
                'contact_phone' => trim((string) ($row['member_phone'] ?? '')) ?: self::PLACEHOLDER_PHONE,
                'total_amount' => $amount,
                'status' => 'confirmed',
            ]);
        });

        return ['status' => 'booked', 'message' => "hall {$hall->id} {$date}..{$endDate} booked"];
    }

    /**
     * The devotee a booking hangs off: matched on phone when the sheet gives
     * one, otherwise a single shared placeholder so the pre-launch rows are
     * obvious in admin and easy to reassign when the real names arrive.
     */
    private function devoteeFor(array $row): Devotee
    {
        $phone = trim((string) ($row['member_phone'] ?? ''));
        $name = trim((string) ($row['member_name'] ?? ''));

        if ($phone !== '') {
            $normalised = PhoneNumber::normalize($phone) ?: $phone;
            $devotee = Devotee::where('phone', $normalised)->first();

            if ($devotee) {
                return $devotee;
            }

            return Devotee::create([
                'name' => $name !== '' ? $name : 'Devotee '.substr($phone, -4),
                'phone' => $normalised,
            ]);
        }

        return Devotee::firstOrCreate(
            ['phone' => self::PLACEHOLDER_PHONE],
            ['name' => 'Pre-launch booking (details pending)'],
        );
    }

    /** @return array<int, array<string, string>> */
    private function readCsv(string $path): array
    {
        $handle = fopen($path, 'r');
        $header = fgetcsv($handle);

        if ($header === false) {
            fclose($handle);

            return [];
        }

        $header = array_map(fn ($h) => trim((string) $h), $header);
        $rows = [];

        while (($line = fgetcsv($handle)) !== false) {
            // Skip blank lines and anything that is all-empty.
            if ($line === [null] || implode('', array_map('strval', $line)) === '') {
                continue;
            }
            $rows[] = array_combine($header, array_pad(array_slice($line, 0, count($header)), count($header), ''));
        }

        fclose($handle);

        return $rows;
    }

    /** @return array{0: string, 1: int} */
    private function target(string $raw): array
    {
        if (! str_contains($raw, ':')) {
            throw new \RuntimeException("target_id must look like pool:1 / seva:2 / hall:2, got '{$raw}'");
        }

        [$type, $id] = explode(':', $raw, 2);

        return [strtolower(trim($type)), (int) trim($id)];
    }

    private function date(string $raw): string
    {
        $raw = trim($raw);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            throw new \RuntimeException("date must be YYYY-MM-DD, got '{$raw}'");
        }

        return $raw;
    }

    private function time(string $raw): string
    {
        $raw = trim($raw);

        if (! preg_match('/^\d{1,2}:\d{2}$/', $raw)) {
            throw new \RuntimeException("slot must be HH:MM, got '{$raw}'");
        }

        return substr('0'.$raw, -5);
    }

    private function hasDate(array $entries, string $date): bool
    {
        foreach ($entries as $entry) {
            if (($entry['date'] ?? null) === $date) {
                return true;
            }
        }

        return false;
    }
}
