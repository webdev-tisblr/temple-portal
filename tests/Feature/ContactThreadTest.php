<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ContactCategory;
use App\Models\ContactMessage;
use App\Models\ContactSubmission;
use App\Models\Devotee;
use App\Services\ContactThreadService;
use Database\Factories\DevoteeFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The contact form is a conversation now (2026-08-29), not a one-way drop box.
 *
 * A devotee used to write in and hear nothing: the trust read the message in
 * an admin inbox and, if they answered at all, answered by phone. The same
 * question therefore arrived three times. An admin can now reply, the devotee
 * reads it in the app, and can write back.
 *
 * The invariant these tests exist to protect is ownership: every read and
 * every reply is scoped to the caller's OWN submissions, because a contact
 * message can contain anything a devotee felt private enough to write to the
 * trust rather than say out loud.
 */
class ContactThreadTest extends TestCase
{
    use RefreshDatabase;

    private function threads(): ContactThreadService
    {
        return app(ContactThreadService::class);
    }

    private function submissionFor(Devotee $devotee): ContactSubmission
    {
        return ContactSubmission::fromDevotee($devotee, [
            'category' => ContactCategory::QUERY->value,
            'subject' => 'Sunday aarti timing',
            'message' => 'What time is the Sunday evening aarti?',
        ], '127.0.0.1');
    }

    public function test_a_devotee_sees_the_trusts_reply_in_their_thread(): void
    {
        $devotee = DevoteeFactory::new()->create(['name' => 'Jayesh']);
        $submission = $this->submissionFor($devotee);

        $this->threads()->replyAsAdmin($submission, 'Sunday aarti is at 7:15 PM.');

        Sanctum::actingAs($devotee);

        $response = $this->getJson("/api/v1/contact/threads/{$submission->id}")->assertOk();

        // The opening message is not a ContactMessage row — it lives on the
        // submission — so the transcript has to stitch the two together.
        $response->assertJsonPath('data.messages.0.from', 'devotee')
            ->assertJsonPath('data.messages.0.body', 'What time is the Sunday evening aarti?')
            ->assertJsonPath('data.messages.1.from', 'admin')
            ->assertJsonPath('data.messages.1.body', 'Sunday aarti is at 7:15 PM.');
    }

    public function test_opening_a_thread_clears_its_unread_badge(): void
    {
        $devotee = DevoteeFactory::new()->create();
        $submission = $this->submissionFor($devotee);
        $this->threads()->replyAsAdmin($submission, 'Answered.');

        Sanctum::actingAs($devotee);

        $this->getJson('/api/v1/contact/threads')
            ->assertOk()
            ->assertJsonPath('data.unread_total', 1)
            ->assertJsonPath('data.threads.0.unread_count', 1)
            ->assertJsonPath('data.threads.0.last_from_trust', true);

        $this->getJson("/api/v1/contact/threads/{$submission->id}")->assertOk();

        $this->getJson('/api/v1/contact/threads')
            ->assertOk()
            ->assertJsonPath('data.unread_total', 0);
    }

    public function test_a_devotee_can_follow_up_and_it_reopens_the_thread_for_the_trust(): void
    {
        $devotee = DevoteeFactory::new()->create();
        $submission = $this->submissionFor($devotee);

        $this->threads()->replyAsAdmin($submission, 'It is at 7:15 PM.');
        $this->assertTrue($submission->fresh()->is_read, 'answering a message marks it read');

        Sanctum::actingAs($devotee);

        $this->postJson("/api/v1/contact/threads/{$submission->id}/reply", [
            'body' => 'Is it the same on a festival day?',
        ])->assertOk()->assertJsonPath('data.from', 'devotee');

        $submission->refresh();

        $this->assertFalse($submission->is_read, 'a follow-up puts the thread back in the trust\'s unread pile');
        $this->assertSame(1, $submission->messages()->unreadByAdmin()->count());
        $this->assertNotNull($submission->last_message_at, 'the thread must re-sort to the top of the inbox');
    }

    public function test_one_devotee_cannot_read_or_answer_anothers_thread(): void
    {
        $author = DevoteeFactory::new()->create();
        $stranger = DevoteeFactory::new()->create();
        $submission = $this->submissionFor($author);
        $this->threads()->replyAsAdmin($submission, 'A private answer.');

        Sanctum::actingAs($stranger);

        // 404, not 403: confirming the id exists would leak that someone else
        // has an open conversation with the trust.
        $this->getJson("/api/v1/contact/threads/{$submission->id}")->assertNotFound();
        $this->postJson("/api/v1/contact/threads/{$submission->id}/reply", ['body' => 'me too'])
            ->assertNotFound();

        $this->getJson('/api/v1/contact/threads')
            ->assertOk()
            ->assertJsonCount(0, 'data.threads');

        $this->assertSame(1, $submission->messages()->count(), 'no message was added by the stranger');
    }

    public function test_a_guest_gets_nowhere(): void
    {
        $devotee = DevoteeFactory::new()->create();
        $submission = $this->submissionFor($devotee);

        $this->getJson('/api/v1/contact/threads')->assertUnauthorized();
        $this->getJson("/api/v1/contact/threads/{$submission->id}")->assertUnauthorized();
    }

    public function test_an_admin_reply_records_who_wrote_it(): void
    {
        $devotee = DevoteeFactory::new()->create();
        $submission = $this->submissionFor($devotee);

        $message = $this->threads()->replyAsAdmin($submission, 'Noted, thank you.');

        $this->assertSame(ContactMessage::AUTHOR_ADMIN, $message->author_type);
        $this->assertTrue($message->isFromAdmin());
        $this->assertNull($message->read_at, 'a reply starts unread — that is what badges it for the devotee');
    }

    public function test_a_submission_with_no_devotee_is_never_notified_at_nobody(): void
    {
        // Submissions taken before the form required a login (2026-08-17)
        // have no devotee to answer. Replying must not blow up on them.
        $orphan = ContactSubmission::create([
            'category' => ContactCategory::QUERY->value,
            'name' => 'Anonymous',
            'phone' => '9999999999',
            'subject' => 'Old message',
            'message' => 'Taken before login was required.',
            'is_read' => false,
        ]);

        $message = $this->threads()->replyAsAdmin($orphan, 'For the record.');

        $this->assertSame('For the record.', $message->body);
        $this->assertNull($orphan->fresh()->devotee_id);
    }
}
