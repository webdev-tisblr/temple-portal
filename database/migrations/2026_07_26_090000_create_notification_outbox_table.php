<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Transactional outbox for queue-backed notifications (Phase H).
 *
 * NotificationService::dispatch() inserts a row here INSIDE the caller's
 * DB transaction, then enqueues the send after commit. If the process
 * dies between commit and enqueue (or Redis is briefly down), the row
 * survives and `notifications:relay-outbox` re-enqueues it — the intent
 * to notify commits atomically with the business change it belongs to.
 *
 * Rows are deleted on successful processing; NotificationLog remains the
 * per-delivery audit trail. This table should be near-empty at rest —
 * sustained growth means the relay or the workers are broken.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_notification_outbox', function (Blueprint $table) {
            $table->id();
            $table->string('key', 64);
            $table->json('context_snapshot');
            $table->string('idempotency_key')->nullable();
            $table->json('only_channels')->nullable();
            $table->string('queue', 32)->default('default');
            // Set when the relay (or the happy path) hands the row to the
            // queue; stale claims (worker died mid-job) become re-claimable.
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->index(['claimed_at', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_notification_outbox');
    }
};
