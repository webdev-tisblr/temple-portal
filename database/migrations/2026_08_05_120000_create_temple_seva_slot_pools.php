<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Shared slot pools: several sevas can draw from ONE capacity pool —
 * booking any member seva consumes the pool's slots, so availability
 * is identical across members.
 *
 * The pool OWNS the slot settings (same v2 slot_config JSON shape as
 * temple_sevas.slot_config). A seva with slot_pool_id set ignores its
 * own slot_config entirely; SevaSlotService resolves config + counts
 * bookings across all pool members.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_seva_slot_pools', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->json('slot_config')->nullable();
            $table->timestamps();
        });

        Schema::table('temple_sevas', function (Blueprint $table) {
            $table->foreignId('slot_pool_id')
                ->nullable()
                ->after('slot_config')
                ->constrained('temple_seva_slot_pools')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('temple_sevas', function (Blueprint $table) {
            $table->dropConstrainedForeignId('slot_pool_id');
        });

        Schema::dropIfExists('temple_seva_slot_pools');
    }
};
