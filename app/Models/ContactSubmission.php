<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ContactCategory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    ];

    protected $casts = [
        'category' => ContactCategory::class,
        'is_read' => 'boolean',
        'read_at' => 'datetime',
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
