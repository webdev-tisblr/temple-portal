<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\BookingStatus;
use App\Models\Devotee;
use App\Models\Hall;
use App\Models\HallBooking;
use App\Models\Seva;
use App\Models\SevaBooking;
use App\Models\SevaSlotPool;
use App\Support\PhoneNumber;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Loads bookings that were taken OFF the platform — on paper, over the phone —
 * so the website stops offering dates that are already spoken for.
 *
 * Built for the 2026-08-15 launch and kept because the same sheet is
 * resubmitted as member details firm up. Every operation is idempotent, so
 * re-uploading a fuller version of the same sheet is always safe.
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
 * The logic lives here rather than in the command because the admin upload
 * page needs exactly the same behaviour, and two copies would drift.
 */
final class ExistingBookingImporter
{
    /** Devotee the placeholder bookings hang off until real names arrive. */
    public const PLACEHOLDER_PHONE = '0000000000';

    /** Column order of both the download template and the upload. */
    public const COLUMNS = [
        'kind', 'target_id', 'target_name', 'date', 'end_date',
        'slot', 'member_name', 'member_phone', 'amount', 'notes',
    ];

    /**
     * Run a whole sheet. Never throws for one bad row — a single typo must
     * not abandon the other forty, so failures are collected per line and
     * reported together.
     *
     * @param  array<int, array<string, string>>  $rows
     * @return array{stats: array<string, int>, lines: array<int, array{line: int, status: string, message: string}>}
     */
    public function import(array $rows, bool $dryRun = false): array
    {
        $stats = ['blocked' => 0, 'booked' => 0, 'already' => 0, 'failed' => 0];
        $lines = [];

        foreach ($rows as $i => $row) {
            $line = $i + 2; // +1 for the header, +1 for 1-based counting

            try {
                $result = match (strtolower(trim((string) ($row['kind'] ?? '')))) {
                    'blackout' => $this->applyBlackout($row, $dryRun),
                    'seva' => $this->applySevaBooking($row, $dryRun),
                    'hall' => $this->applyHallBooking($row, $dryRun),
                    default => throw new \RuntimeException("unknown kind '".($row['kind'] ?? '')."'"),
                };
            } catch (\Throwable $e) {
                $result = ['status' => 'failed', 'message' => $e->getMessage()];
            }

            $stats[$result['status']]++;
            $lines[] = ['line' => $line] + $result;
        }

        return ['stats' => $stats, 'lines' => $lines];
    }

    /**
     * Everything currently blocked or booked, as a sheet to fill in.
     *
     * This is the point of the download: the trust gets back exactly the rows
     * already in effect, with member_name / member_phone / amount blank, fills
     * them in, and uploads the same file. Because every row is idempotent, the
     * blackouts stay put and the bookings gain their details.
     */
    public function exportCurrent(): string
    {
        $rows = [];

        foreach (SevaSlotPool::orderBy('id')->get() as $pool) {
            foreach ((array) (((array) $pool->slot_config)['blackout_dates'] ?? []) as $entry) {
                $rows[] = ['blackout', 'pool:'.$pool->id, $pool->name, $entry['date'] ?? '', '', '', '', '', '', $entry['reason'] ?? ''];
            }
        }

        foreach (Seva::orderBy('id')->get() as $seva) {
            foreach ((array) (((array) $seva->slot_config)['blackout_dates'] ?? []) as $entry) {
                $rows[] = ['blackout', 'seva:'.$seva->id, $seva->name_en ?: $seva->name_gu, $entry['date'] ?? '', '', '', '', '', '', $entry['reason'] ?? ''];
            }
        }

        foreach (Hall::orderBy('id')->get() as $hall) {
            foreach ((array) ($hall->blackout_dates ?? []) as $entry) {
                $rows[] = ['blackout', 'hall:'.$hall->id, $hall->getAttributes()['name'] ?? '', $entry['date'] ?? '', '', '', '', '', '', $entry['reason'] ?? ''];
            }
        }

        // Real bookings already carrying details are included too, so the
        // sheet is a complete picture rather than only the gaps.
        foreach (SevaBooking::with('seva', 'devotee')->whereIn('status', ['pending', 'confirmed', 'completed'])->orderBy('booking_date')->get() as $b) {
            $phone = (string) ($b->devotee?->phone ?? '');
            $rows[] = [
                'seva', (string) $b->seva_id, $b->seva?->name_en ?: '', $b->booking_date?->toDateString() ?? '', '',
                $b->slot_time === 'full_day' ? '' : (string) substr((string) $b->slot_time, 0, 5),
                (string) ($b->devotee_name_for_seva ?: ($b->devotee?->name ?? '')),
                $phone === self::PLACEHOLDER_PHONE ? '' : $phone,
                (string) $b->total_amount, (string) ($b->notes ?? ''),
            ];
        }

        foreach (HallBooking::with('hall', 'devotee')->whereIn('status', ['pending', 'confirmed', 'completed'])->orderBy('booking_date')->get() as $b) {
            $phone = (string) ($b->contact_phone ?? '');
            $rows[] = [
                'hall', (string) $b->hall_id, $b->hall?->getAttributes()['name'] ?? '', $b->booking_date?->toDateString() ?? '',
                $b->end_date?->toDateString() ?? '', '',
                (string) ($b->contact_name ?? ''),
                $phone === self::PLACEHOLDER_PHONE ? '' : $phone,
                (string) $b->total_amount, (string) ($b->purpose ?? ''),
            ];
        }

        $out = fopen('php://temp', 'r+');
        fputcsv($out, self::COLUMNS);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
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
    public function applyBlackout(array $row, bool $dryRun): array
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
    public function applySevaBooking(array $row, bool $dryRun): array
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
    public function applyHallBooking(array $row, bool $dryRun): array
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
    public function devoteeFor(array $row): Devotee
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
    public function readCsv(string $path): array
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
    public function target(string $raw): array
    {
        if (! str_contains($raw, ':')) {
            throw new \RuntimeException("target_id must look like pool:1 / seva:2 / hall:2, got '{$raw}'");
        }

        [$type, $id] = explode(':', $raw, 2);

        return [strtolower(trim($type)), (int) trim($id)];
    }

    public function date(string $raw): string
    {
        $raw = trim($raw);

        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            throw new \RuntimeException("date must be YYYY-MM-DD, got '{$raw}'");
        }

        return $raw;
    }

    public function time(string $raw): string
    {
        $raw = trim($raw);

        if (! preg_match('/^\d{1,2}:\d{2}$/', $raw)) {
            throw new \RuntimeException("slot must be HH:MM, got '{$raw}'");
        }

        return substr('0'.$raw, -5);
    }

    public function hasDate(array $entries, string $date): bool
    {
        foreach ($entries as $entry) {
            if (($entry['date'] ?? null) === $date) {
                return true;
            }
        }

        return false;
    }
}
