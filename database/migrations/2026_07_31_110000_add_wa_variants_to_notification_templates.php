<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-language WhatsApp template variants on a single template row:
 *
 *   wa_variants = {
 *     "gu": {"template_name": "...", "language_code": "gu", "components": [...]},
 *     "hi": {...}, "en": {...}
 *   }
 *
 * The legacy wa_template_name / wa_template_language / wa_components
 * columns remain the fallback for rows saved before this feature (and
 * are kept mirrored to the gu variant on save).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_notification_templates', function (Blueprint $table) {
            $table->json('wa_variants')->nullable()->after('wa_components');
        });
    }

    public function down(): void
    {
        Schema::table('temple_notification_templates', function (Blueprint $table) {
            $table->dropColumn('wa_variants');
        });
    }
};
