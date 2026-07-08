<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // The "Full Week" booking mode was removed. Any seva still set to it
        // is converted to "Full Day" so its Booking Mode stays valid in admin
        // and it keeps a working, bookable configuration.
        foreach (DB::table('temple_sevas')->get() as $seva) {
            $config = json_decode($seva->slot_config ?? '', true);

            if (is_array($config) && ($config['slot_type'] ?? null) === 'full_week') {
                $config['slot_type'] = 'full_day';
                DB::table('temple_sevas')
                    ->where('id', $seva->id)
                    ->update(['slot_config' => json_encode($config)]);
            }
        }
    }

    public function down(): void
    {
        // Non-reversible data normalization; no-op.
    }
};
