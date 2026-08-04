<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // (a) ENUM → VARCHAR so admin-created category slugs are storable.
        // MODIFY keeps existing values and the temple_sevas_category_index.
        DB::statement('ALTER TABLE temple_sevas MODIFY category VARCHAR(64) NOT NULL');

        // (b) Managed category list (mirror of temple_gallery_categories).
        Schema::create('temple_seva_categories', function (Blueprint $table) {
            $table->id();
            // Stored on temple_sevas.category as a loose slug reference
            // (existing rows predate this table).
            $table->string('slug')->unique();
            $table->string('name_gu');
            $table->string('name_hi')->nullable();
            $table->string('name_en')->nullable();
            $table->integer('sort_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_seva_categories');
        // Deliberately NOT reverting VARCHAR back to the ENUM: rows may hold
        // admin-created slugs the old ENUM would reject, which would make the
        // rollback itself fail. VARCHAR is a safe superset of the ENUM.
    }
};
