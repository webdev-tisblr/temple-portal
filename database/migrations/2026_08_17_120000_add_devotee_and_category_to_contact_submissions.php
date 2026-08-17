<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contact form now requires a login (2026-08-17), so every new submission is
 * attributable to a devotee and its name/phone are read from that profile
 * rather than retyped (and rather than being spoofable by the client).
 *
 * `category` lets a devotee say what kind of message this is — a suggestion,
 * a complaint, a query — instead of everything arriving as one undifferentiated
 * pile the trust has to triage by reading.
 *
 * devotee_id is NULLABLE on purpose: submissions taken before this change have
 * no devotee to point at, and back-filling them would be a guess. foreignUuid
 * because temple_devotees.id is a char(36) UUID.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temple_contact_submissions', function (Blueprint $table) {
            $table->foreignUuid('devotee_id')
                ->nullable()
                ->after('id')
                ->constrained('temple_devotees')
                ->nullOnDelete();

            $table->string('category', 30)->default('query')->after('devotee_id');

            $table->index('category');
        });
    }

    public function down(): void
    {
        Schema::table('temple_contact_submissions', function (Blueprint $table) {
            $table->dropForeign(['devotee_id']);
            $table->dropIndex(['category']);
            $table->dropColumn(['devotee_id', 'category']);
        });
    }
};
