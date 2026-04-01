<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceToken extends Model
{
    protected $table = 'temple_device_tokens';

    protected $fillable = [
        'devotee_id',
        'token',
        'platform',
        'is_active',
        'last_used_at',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'last_used_at' => 'datetime',
    ];

    public function devotee(): BelongsTo
    {
        return $this->belongsTo(Devotee::class, 'devotee_id');
    }
}
