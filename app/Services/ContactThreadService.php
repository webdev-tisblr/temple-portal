<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminUser;
use App\Models\ContactMessage;
use App\Models\ContactSubmission;
use App\Models\Devotee;
use App\Models\SystemSetting;
use App\Services\Notifications\NotificationService;
use Illuminate\Support\Facades\DB;

/**
 * The one place a contact conversation gains a turn.
 *
 * Both sides post through here rather than touching ContactMessage directly,
 * because a reply is never just a row: it re-stamps the thread so the right
 * inbox sorts it to the top, and an admin reply has to reach the devotee —
 * which is the entire point of making the form two-way.
 */
class ContactThreadService
{
    public function __construct(private NotificationService $notifications) {}

    /**
     * The trust answers a devotee.
     *
     * The notification is dispatched AFTER the transaction commits: it fans
     * out to WhatsApp/push/email, and a reply the devotee has been told about
     * but which was then rolled back is worse than a slightly late message.
     */
    public function replyAsAdmin(ContactSubmission $submission, string $body, ?AdminUser $admin = null): ContactMessage
    {
        $message = DB::transaction(function () use ($submission, $body, $admin): ContactMessage {
            // Answering it means someone has read it.
            if (! $submission->is_read) {
                $submission->update(['is_read' => true, 'read_at' => now()]);
            }

            // Devotee follow-ups are answered now, so they are no longer
            // waiting in the admin's unread count.
            $submission->messages()->unreadByAdmin()->update(['read_at' => now()]);

            return $submission->reply(ContactMessage::AUTHOR_ADMIN, $body, $admin?->getKey());
        });

        DB::afterCommit(fn () => $this->notifyDevotee($submission, $message));

        return $message;
    }

    /** The devotee follows up on their own thread. */
    public function replyAsDevotee(ContactSubmission $submission, string $body): ContactMessage
    {
        return DB::transaction(fn (): ContactMessage => $submission->reply(ContactMessage::AUTHOR_DEVOTEE, $body));
    }

    /**
     * Mark every admin reply in a thread as seen by the devotee. Returns how
     * many rows changed, so a caller can skip work when nothing was unread.
     */
    public function markReadByDevotee(ContactSubmission $submission): int
    {
        return $submission->messages()->unreadByDevotee()->update(['read_at' => now()]);
    }

    /** Threads with an admin reply this devotee has not opened. */
    public function unreadCountFor(Devotee $devotee): int
    {
        return ContactMessage::query()
            ->unreadByDevotee()
            ->whereHas('submission', fn ($q) => $q->where('devotee_id', $devotee->getKey()))
            ->count();
    }

    /**
     * Tell the devotee the trust has written back.
     *
     * Nothing sends unless an admin has created and enabled a
     * `contact.replied` template — same rule as every other trigger. A
     * submission taken before the login requirement (2026-08-17) has no
     * devotee to notify, so it is skipped rather than dispatched at nobody.
     */
    private function notifyDevotee(ContactSubmission $submission, ContactMessage $message): void
    {
        $submission->loadMissing('devotee');

        if ($submission->devotee === null) {
            return;
        }

        $this->notifications->dispatch(
            'contact.replied',
            [
                'submission' => $submission,
                'devotee' => $submission->devotee,
                'reply' => $message,
                'reply_body' => $message->body,
                // Resolved label, not the enum — formatForDisplay would print
                // the raw backing value ("seva_request").
                'category_label' => $submission->category->label(),
                'trust_name' => SystemSetting::getValue('trust_name', 'Shree Patadiya Hanumanji Seva Trust'),
            ],
            idempotencyKey: "contact-reply:{$message->getKey()}",
        );
    }
}
