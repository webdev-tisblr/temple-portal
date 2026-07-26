<?php

namespace Tests\Feature;

use App\Jobs\SendQueuedNotification;
use App\Models\NotificationOutbox;
use App\Services\Notifications\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Feature coverage for the Phase H transactional notification outbox.
 *
 * Guarantees exercised here:
 *   (a) queue-backed dispatch writes an outbox row + enqueues the job
 *       after commit, on the right queue (otp priority for auth.otp),
 *   (b) a ROLLED-BACK caller transaction leaves no outbox row and no
 *       job — the atomicity the outbox exists for,
 *   (c) the job deletes its row after processing; a job whose row is
 *       already gone no-ops (at-least-once enqueue, at-most-once send),
 *   (d) the relay re-enqueues stranded rows but leaves fresh ones alone,
 *   (e) flag off ⇒ no outbox writes (legacy inline behaviour).
 *
 * MySQL-only project: requires the `temple_portal_test` database.
 */
class NotificationOutboxTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['notifications.via_queue' => true]);
    }

    private function dispatchSample(?string $key = 'auth.otp'): void
    {
        app(NotificationService::class)->dispatch(
            $key,
            ['phone' => '9999999999', 'otp' => '123456'],
            'outbox-test-'.uniqid(),
        );
    }

    public function test_dispatch_writes_outbox_row_and_enqueues_on_priority_queue(): void
    {
        Queue::fake();

        $this->dispatchSample('auth.otp');

        $this->assertSame(1, NotificationOutbox::count());
        $row = NotificationOutbox::first();
        $this->assertSame('auth.otp', $row->key);
        $this->assertSame('otp', $row->queue);
        $this->assertNotNull($row->claimed_at, 'happy path claims the row when enqueueing');

        Queue::assertPushedOn('otp', SendQueuedNotification::class,
            fn (SendQueuedNotification $job) => $job->outboxId === $row->id);
    }

    public function test_rolled_back_transaction_leaves_no_row_and_no_job(): void
    {
        Queue::fake();

        try {
            DB::transaction(function () {
                $this->dispatchSample();
                throw new \RuntimeException('force rollback');
            });
        } catch (\RuntimeException) {
            // expected
        }

        $this->assertSame(0, NotificationOutbox::count());
        Queue::assertNothingPushed();
    }

    public function test_job_deletes_row_and_double_processing_noops(): void
    {
        Queue::fake();
        $this->dispatchSample();
        $row = NotificationOutbox::first();

        // Run the job by hand (no enabled templates in the test DB, so the
        // send resolves to a clean "nothing to send" — the row lifecycle is
        // what's under test).
        $job = new SendQueuedNotification($row->key, [], $row->idempotency_key, null, $row->id);
        $job->handle(app(NotificationService::class));

        $this->assertSame(0, NotificationOutbox::count());

        // Second delivery of the same job (relay raced the happy path).
        $job->handle(app(NotificationService::class)); // must not throw
        $this->assertSame(0, NotificationOutbox::count());
    }

    public function test_relay_reenqueues_stranded_rows_only(): void
    {
        Queue::fake();

        // Stranded: committed but never claimed (crash before enqueue).
        $stranded = NotificationOutbox::create([
            'key' => 'auth.otp', 'context_snapshot' => ['otp' => '1'],
            'queue' => 'otp',
        ]);
        $stranded->forceFill(['created_at' => now()->subMinutes(10)])->save();

        // Fresh: just written, happy path still in flight — must be left alone.
        NotificationOutbox::create([
            'key' => 'auth.otp', 'context_snapshot' => ['otp' => '2'],
            'queue' => 'otp',
        ]);

        Artisan::call('notifications:relay-outbox');

        Queue::assertPushed(SendQueuedNotification::class, 1);
        Queue::assertPushed(SendQueuedNotification::class,
            fn (SendQueuedNotification $job) => $job->outboxId === $stranded->id);
        $this->assertNotNull($stranded->fresh()->claimed_at);
    }

    public function test_flag_off_writes_no_outbox_rows(): void
    {
        config(['notifications.via_queue' => false]);
        Queue::fake();

        $this->dispatchSample();

        $this->assertSame(0, NotificationOutbox::count());
        Queue::assertNothingPushed();
    }
}
