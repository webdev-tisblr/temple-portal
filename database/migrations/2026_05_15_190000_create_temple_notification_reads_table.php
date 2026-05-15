<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-devotee read state for broadcast notifications. One row is
     * created the first time a devotee opens a notification in the
     * inbox; absence of a row means "unread". The (devotee_id,
     * notification_id) pair is unique so the existence check is O(1).
     */
    public function up(): void
    {
        Schema::create('temple_notification_reads', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('devotee_id')
                ->constrained('temple_devotees')
                ->cascadeOnDelete();
            $table->foreignId('notification_id')
                ->constrained('temple_notifications')
                ->cascadeOnDelete();
            $table->timestamp('read_at')->useCurrent();
            $table->timestamps();

            $table->unique(['devotee_id', 'notification_id']);
            $table->index(['devotee_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_notification_reads');
    }
};
