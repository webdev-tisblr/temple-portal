<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Track per-devotee dismissal state for inbox rows. A dismissed
     * notification is hidden from the inbox without affecting the
     * underlying broadcast (which is shared across devotees and stays
     * intact). Different from read_at: a read row is still listed in
     * the inbox; a dismissed row isn't.
     */
    public function up(): void
    {
        Schema::table('temple_notification_reads', function (Blueprint $table) {
            $table->timestamp('dismissed_at')->nullable()->after('read_at');
            $table->index(['devotee_id', 'dismissed_at']);
        });
    }

    public function down(): void
    {
        Schema::table('temple_notification_reads', function (Blueprint $table) {
            $table->dropIndex(['devotee_id', 'dismissed_at']);
            $table->dropColumn('dismissed_at');
        });
    }
};
