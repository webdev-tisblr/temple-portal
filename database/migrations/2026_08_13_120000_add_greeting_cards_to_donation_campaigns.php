<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Greeting cards for campaign donations (2026-08-13).
 *
 * A campaign donation reaches the card pipeline with `donation_type_id = NULL`:
 * in campaign mode the donate form deliberately hides the type dropdown and
 * offers sub-causes instead, so there is no DonationType to hang artwork on and
 * GreetingCardService bails at its first guard. Every campaign donation on
 * production has therefore produced no card at all.
 *
 * The fix is to let the CAMPAIGN carry the artwork, exactly as DonationType and
 * Seva already do. Same four columns Seva gained in
 * 2026_08_05_100000_add_multilingual_greeting_cards.php, so all three models now
 * share one shape and the overlay editor works against any of them unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_donation_campaigns', function (Blueprint $table): void {
            // Gujarati is the default AND the fallback: templateForLocale()
            // serves this one whenever a locale slot is empty, so a
            // half-finished upload degrades quietly rather than breaking.
            $table->string('greeting_card_template', 500)->nullable()->after('image_path');
            $table->string('greeting_card_template_hi', 500)->nullable()->after('greeting_card_template');
            $table->string('greeting_card_template_en', 500)->nullable()->after('greeting_card_template_hi');

            // Overlay positions. Shared across all three languages, which is
            // why the Hindi/English images must match the Gujarati one's
            // dimensions — the coordinates are absolute, not proportional.
            $table->json('greeting_card_config')->nullable()->after('greeting_card_template_en');
        });
    }

    public function down(): void
    {
        Schema::table('temple_donation_campaigns', function (Blueprint $table): void {
            $table->dropColumn([
                'greeting_card_template',
                'greeting_card_template_hi',
                'greeting_card_template_en',
                'greeting_card_config',
            ]);
        });
    }
};
