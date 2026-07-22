<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Optional share caption for status templates. When a devotee shares the
 * generated status image, this text is attached as the share body
 * ("intent") in WhatsApp/socials. Localized (gu/hi/en) to mirror the
 * template title; all-empty means no text is passed along at all.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_status_templates', function (Blueprint $t) {
            $t->text('share_text_gu')->nullable()->after('title_en');
            $t->text('share_text_hi')->nullable()->after('share_text_gu');
            $t->text('share_text_en')->nullable()->after('share_text_hi');
        });
    }

    public function down(): void
    {
        Schema::table('temple_status_templates', function (Blueprint $t) {
            $t->dropColumn(['share_text_gu', 'share_text_hi', 'share_text_en']);
        });
    }
};
