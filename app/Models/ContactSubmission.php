<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContactCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ContactSubmission extends Model
{
    protected $table = 'temple_contact_submissions';

    protected $fillable = [
        'devotee_id',
        'category',
        'name',
        'phone',
        'email',
        'subject',
        'message',
        'ip_address',
        'is_read',
        'read_at',
        'last_message_at',
        'is_closed',
    ];

    protected $casts = [
        'category' => ContactCategory::class,
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'last_message_at' => 'datetime',
        'is_closed' => 'boolean',
    ];

    /**
     * The devotee who sent this. Null only for submissions taken before the
     * form required a login (2026-08-17).
     */
    public function devotee(): BelongsTo
    {
        return $this->belongsTo(Devotee::class, 'devotee_id');
    }

    /**
     * Every turn AFTER the opening message. The opening message is this
     * row's own `message` column — see the migration for why it was not
     * copied in here.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(ContactMessage::class, 'contact_submission_id')->orderBy('created_at');
    }

    /**
     * Post a reply into this thread and stamp the conversation so an inbox
     * sorted by "who is waiting" puts it on top.
     *
     * Both directions go through here so the two callers cannot forget the
     * stamp — an unsorted thread is invisible to whoever needs to answer it.
     */
    public function reply(string $authorType, string $body, ?int $adminUserId = null): ContactMessage
    {
        $message = $this->messages()->create([
            'author_type' => $authorType,
            'admin_user_id' => $authorType === ContactMessage::AUTHOR_ADMIN ? $adminUserId : null,
            'body' => $body,
        ]);

        $attributes = ['last_message_at' => $message->created_at];

        // A devotee following up re-opens the thread for the trust: it is
        // waiting on an answer again, whatever it was marked before.
        if ($authorType === ContactMessage::AUTHOR_DEVOTEE) {
            $attributes['is_read'] = false;
            $attributes['is_closed'] = false;
        }

        $this->update($attributes);

        return $message;
    }

    /** Admin replies this devotee has not opened yet. */
    public function unreadForDevoteeCount(): int
    {
        return $this->messages()->unreadByDevotee()->count();
    }

    /**
     * Build a submission from the logged-in devotee: identity always comes
     * from the profile, never from the request body, so a client cannot post
     * someone else's name or number. Shared by the web and API controllers so
     * the two can't drift.
     *
     * @param  array{category?: string|null, subject: string, message: string}  $input
     */
    public static function fromDevotee(Devotee $devotee, array $input, ?string $ip): self
    {
        return self::create([
            'devotee_id' => $devotee->id,
            'category' => $input['category'] ?? ContactCategory::QUERY->value,
            'name' => $devotee->name,
            'phone' => $devotee->phone,
            'email' => $devotee->email,
            'subject' => $input['subject'],
            'message' => $input['message'],
            'ip_address' => $ip,
            'is_read' => false,
        ]);
    }
}
