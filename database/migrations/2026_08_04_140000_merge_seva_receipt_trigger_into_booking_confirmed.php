<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The separate `seva.receipt` trigger merged into `seva.booking.confirmed`
 * (2026-08-04): payment captured → receipt generated → ONE confirmation
 * message carrying the receipt.
 *
 * Re-keys every seva.receipt template row to seva.booking.confirmed so no
 * row points at a retired key. Where an ENABLED receipt row lands on a
 * channel that already has an enabled plain confirmed row, the plain row
 * is disabled — otherwise devotees would get two messages per channel
 * (both seeded email templates are enabled in production).
 *
 * The receipt-derived row wins because its body/placeholders already
 * carry the receipt number and attachment copy — the whole point of the
 * merge. Its placeholder_map keeps resolving: the merged dispatch
 * context is a superset of the old seva.receipt context.
 */
return new class extends Migration
{
    public function up(): void
    {
        $receiptRows = DB::table('temple_notification_templates')
            ->where('key', 'seva.receipt')
            ->get();

        foreach ($receiptRows as $row) {
            if ($row->is_enabled) {
                DB::table('temple_notification_templates')
                    ->where('key', 'seva.booking.confirmed')
                    ->where('channel', $row->channel)
                    ->where('is_enabled', true)
                    ->update([
                        'is_enabled' => false,
                        'description' => DB::raw(
                            "CONCAT(COALESCE(description, ''), ' [auto-disabled ".now()->toDateString().": superseded by the merged receipt-carrying template]')"
                        ),
                    ]);
            }

            DB::table('temple_notification_templates')
                ->where('id', $row->id)
                ->update(['key' => 'seva.booking.confirmed']);
        }
    }

    public function down(): void
    {
        // Irreversible data merge — intentionally a no-op.
    }
};
