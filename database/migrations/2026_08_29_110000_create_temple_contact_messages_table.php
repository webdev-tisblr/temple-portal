<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Turn the contact form into a conversation (2026-08-29).
 *
 * Until now a devotee's message went one way: into an admin inbox, answered —
 * if at all — by someone phoning them back. The devotee had no way to see that
 * the trust had responded, so the same question arrived three times.
 *
 * A submission is now the THREAD, and every turn after the opening message is
 * a row here. The opening message deliberately stays where it is, on
 * `temple_contact_submissions.message`: it is what every existing admin screen,
 * export and notification template reads, and copying it into this table would
 * leave two copies to drift apart. Readers concatenate.
 *
 * `read_at` is "read by the OTHER side" — set when the devotee opens a thread
 * carrying admin replies, and when an admin opens one carrying devotee
 * follow-ups. It is what drives the unread badge in the app.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('temple_contact_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contact_submission_id')
                ->constrained('temple_contact_submissions')
                ->cascadeOnDelete();

            // Who is speaking. Not derived from which id column is filled:
            // an admin reply keeps its author even after that admin account
            // is deleted (admin_user_id then goes null).
            $table->enum('author_type', ['devotee', 'admin']);

            $table->foreignId('admin_user_id')
                ->nullable()
                ->constrained('temple_admin_users')
                ->nullOnDelete();

            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            // The two queries this table serves: render one thread in order,
            // and count what the reader has not seen yet.
            $table->index(['contact_submission_id', 'created_at']);
            $table->index(['contact_submission_id', 'author_type', 'read_at'], 'contact_msg_unread_idx');
        });

        Schema::table('temple_contact_submissions', function (Blueprint $table) {
            // Sorting an inbox by "who is waiting on us" needs the time of the
            // LAST turn, not the first. Nullable: a thread with no replies yet
            // sorts on created_at, which is what the admin list already does.
            $table->timestamp('last_message_at')->nullable()->after('read_at');

            // The devotee's view of the thread. `closed` hides it from the
            // "needs an answer" filter without deleting anything.
            $table->boolean('is_closed')->default(false)->after('last_message_at');

            $table->index('last_message_at');
        });
    }

    public function down(): void
    {
        Schema::table('temple_contact_submissions', function (Blueprint $table) {
            $table->dropIndex(['last_message_at']);
            $table->dropColumn(['last_message_at', 'is_closed']);
        });

        Schema::dropIfExists('temple_contact_messages');
    }
};
