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
        Schema::create('temple_trustees', function (Blueprint $table) {
            $table->id();
            $table->string('name_gu', 255);
            $table->string('name_hi', 255)->nullable();
            $table->string('name_en', 255)->nullable();
            $table->string('role_gu', 255)->nullable();
            $table->string('role_hi', 255)->nullable();
            $table->string('role_en', 255)->nullable();
            $table->string('location', 255)->nullable();
            $table->string('photo_path', 500)->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        // Preserve the four trustees previously hardcoded in the lang files.
        if (DB::table('temple_trustees')->count() === 0) {
            $now = now();
            DB::table('temple_trustees')->insert([
                ['name_gu' => 'શ્રી રામભાઈ પટેલ', 'role_gu' => 'પ્રમુખ (Chairman)', 'location' => 'ગાંધીધામ, કચ્છ', 'sort_order' => 1, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
                ['name_gu' => 'શ્રી ભાવેશભાઈ શાહ', 'role_gu' => 'સચિવ (Secretary)', 'location' => 'ગાંધીધામ, કચ્છ', 'sort_order' => 2, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
                ['name_gu' => 'શ્રી નટવરભાઈ ઠાકર', 'role_gu' => 'કોષાધ્યક્ષ (Treasurer)', 'location' => 'ગાંધીધામ, કચ્છ', 'sort_order' => 3, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
                ['name_gu' => 'શ્રી જિતેન્દ્રભાઈ દવે', 'role_gu' => 'ટ્રસ્ટી (Trustee)', 'location' => 'ગાંધીધામ, કચ્છ', 'sort_order' => 4, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now],
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_trustees');
    }
};
