<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * whatsapp_templates_cache — local mirror of approved templates pulled
 * from the Meta WhatsApp Cloud API.
 *
 * Refreshed when an admin clicks "Sync templates" on the WhatsApp
 * settings page. Drives the template-name dropdown when editing a
 * notification_template row whose channel = whatsapp.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('temple_whatsapp_templates_cache', function (Blueprint $table) {
            $table->id();

            $table->string('name')->index();
            $table->string('language', 10)->default('en');
            $table->string('category')->nullable()
                ->comment('UTILITY / MARKETING / AUTHENTICATION');
            $table->string('status')
                ->comment('Status reported by Meta — APPROVED / PENDING / REJECTED');

            // Full component breakdown returned by the API — header / body /
            // footer / buttons + their parameter shape. Stored verbatim so
            // the admin UI can render param mappers without round-tripping.
            $table->json('components')->nullable();

            // Echo Meta's own template id so re-syncs can reconcile by it.
            $table->string('meta_template_id')->nullable();

            $table->timestamp('synced_at')->nullable();

            $table->timestamps();

            $table->unique(['name', 'language']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_whatsapp_templates_cache');
    }
};
