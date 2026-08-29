<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Models\ContactMessage;
use App\Models\ContactSubmission;
use App\Services\ContactThreadService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The devotee's side of the contact conversation (2026-08-29).
 *
 * Every route here is scoped to the caller's own submissions by
 * `ownThread()`. There is no admin surface in this controller — the trust
 * answers from Filament.
 */
class ContactThreadController extends BaseApiController
{
    public function __construct(private ContactThreadService $threads) {}

    /**
     * The devotee's conversations, newest activity first, each with a
     * preview and its own unread count so the list can badge without a
     * second request per row.
     */
    public function index(Request $request): JsonResponse
    {
        $devotee = $request->user();

        $threads = ContactSubmission::query()
            ->where('devotee_id', $devotee->getKey())
            ->withCount([
                'messages as unread_count' => fn ($q) => $q->unreadByDevotee(),
                'messages as reply_count' => fn ($q) => $q->where('author_type', ContactMessage::AUTHOR_ADMIN),
            ])
            ->with(['messages' => fn ($q) => $q->latest('created_at')->limit(1)])
            // A thread with no reply yet has no last_message_at; fall back to
            // when it was opened so it still sorts sensibly among the rest.
            ->orderByRaw('COALESCE(last_message_at, created_at) DESC')
            ->limit(100)
            ->get()
            ->map(function (ContactSubmission $submission): array {
                $last = $submission->messages->first();

                return [
                    'id' => $submission->id,
                    'category' => $submission->category->value,
                    'category_label' => $submission->category->label(),
                    'subject' => $submission->subject,
                    // What to show under the subject: the newest turn if there
                    // is one, else the message they originally sent.
                    'preview' => $last?->body ?? $submission->message,
                    'last_from_trust' => $last?->isFromAdmin() ?? false,
                    'unread_count' => (int) $submission->unread_count,
                    'reply_count' => (int) $submission->reply_count,
                    'is_closed' => (bool) $submission->is_closed,
                    'created_at' => $submission->created_at?->toIso8601String(),
                    'last_message_at' => ($submission->last_message_at ?? $submission->created_at)?->toIso8601String(),
                ];
            });

        return $this->success([
            'threads' => $threads,
            'unread_total' => $this->threads->unreadCountFor($devotee),
        ]);
    }

    /**
     * One conversation in full. Opening it marks the trust's replies read —
     * that is what clears the badge, so it happens on read, not on a
     * separate call the app could forget to make.
     */
    public function show(Request $request, int $submission): JsonResponse
    {
        $thread = $this->ownThread($request, $submission);

        if ($thread === null) {
            return $this->error('આ સંદેશ મળ્યો નથી.', 404);
        }

        $this->threads->markReadByDevotee($thread);
        $thread->load(['messages.adminUser']);

        return $this->success([
            'id' => $thread->id,
            'category' => $thread->category->value,
            'category_label' => $thread->category->label(),
            'subject' => $thread->subject,
            'is_closed' => (bool) $thread->is_closed,
            'created_at' => $thread->created_at?->toIso8601String(),
            // The opening message is not a ContactMessage row — it lives on
            // the submission — so it is emitted here as the first turn.
            'messages' => collect([[
                'id' => 0,
                'from' => 'devotee',
                'author' => $thread->name,
                'body' => $thread->message,
                'created_at' => $thread->created_at?->toIso8601String(),
            ]])->concat(
                $thread->messages->map(fn (ContactMessage $m): array => [
                    'id' => $m->id,
                    'from' => $m->author_type,
                    'author' => $m->isFromAdmin()
                        ? ($m->adminUser?->name ?? __('contact.the_trust'))
                        : $thread->name,
                    'body' => $m->body,
                    'created_at' => $m->created_at?->toIso8601String(),
                ])
            )->values(),
        ]);
    }

    /**
     * The devotee follows up on their own thread.
     *
     * Rate-limited per devotee like the original submission is — a reply box
     * is exactly as spammable as a contact form, and a whole family shares
     * one temple wifi, so per-IP would be wrong here too.
     */
    public function reply(Request $request, int $submission): JsonResponse
    {
        $thread = $this->ownThread($request, $submission);

        if ($thread === null) {
            return $this->error('આ સંદેશ મળ્યો નથી.', 404);
        }

        $key = 'contact-reply:'.$request->user()->getKey();
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return $this->error('ઘણા બધા પ્રયાસો. કૃપા કરી થોડા સમય પછી ફરી પ્રયાસ કરો.', 429);
        }
        RateLimiter::hit($key, 3600);

        $validated = $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        $message = $this->threads->replyAsDevotee($thread, $validated['body']);

        return $this->success([
            'id' => $message->id,
            'from' => $message->author_type,
            'author' => $thread->name,
            'body' => $message->body,
            'created_at' => $message->created_at?->toIso8601String(),
        ], 'તમારો સંદેશ મોકલાયો.');
    }

    /**
     * Load a thread ONLY if it belongs to the caller. Returning null rather
     * than 403 on someone else's id keeps the endpoint from confirming that
     * a given submission exists at all.
     */
    private function ownThread(Request $request, int $id): ?ContactSubmission
    {
        return ContactSubmission::query()
            ->whereKey($id)
            ->where('devotee_id', $request->user()->getKey())
            ->first();
    }
}
