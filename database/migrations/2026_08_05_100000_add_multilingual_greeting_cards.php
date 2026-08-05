<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Greeting cards go multilingual + sevas get their own greeting card.
 *
 * - Donation types gain per-language background images (existing
 *   `greeting_card_template` stays the Gujarati/default image; hi/en
 *   fall back to it when not uploaded).
 * - Sevas gain the same four greeting-card columns the donation type
 *   has (column names deliberately match so the drag-drop overlay
 *   editor blade works unchanged — same trick as StatusTemplate).
 * - Seva bookings gain `greeting_card_path` (r2_private cache path,
 *   NULLed by the sweep, regenerated on miss).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_donation_types', function (Blueprint $table) {
            $table->string('greeting_card_template_hi', 500)->nullable()->after('greeting_card_template');
            $table->string('greeting_card_template_en', 500)->nullable()->after('greeting_card_template_hi');
        });

        Schema::table('temple_sevas', function (Blueprint $table) {
            $table->string('greeting_card_template', 500)->nullable()->after('linked_products');
            $table->string('greeting_card_template_hi', 500)->nullable()->after('greeting_card_template');
            $table->string('greeting_card_template_en', 500)->nullable()->after('greeting_card_template_hi');
            $table->json('greeting_card_config')->nullable()->after('greeting_card_template_en');
        });

        Schema::table('temple_seva_bookings', function (Blueprint $table) {
            $table->string('greeting_card_path', 500)->nullable()->after('receipt_path');
        });
    }

    public function down(): void
    {
        Schema::table('temple_donation_types', function (Blueprint $table) {
            $table->dropColumn(['greeting_card_template_hi', 'greeting_card_template_en']);
        });

        Schema::table('temple_sevas', function (Blueprint $table) {
            $table->dropColumn([
                'greeting_card_template',
                'greeting_card_template_hi',
                'greeting_card_template_en',
                'greeting_card_config',
            ]);
        });

        Schema::table('temple_seva_bookings', function (Blueprint $table) {
            $table->dropColumn('greeting_card_path');
        });
    }
};
