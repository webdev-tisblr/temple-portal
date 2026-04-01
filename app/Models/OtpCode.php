<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

class OtpCode extends Model
{
    use Prunable;

    protected $table = 'temple_otp_codes';

    public $timestamps = false;

    protected $fillable = [
        'phone',
        'code',
        'purpose',
        'attempts',
        'expires_at',
        'verified_at',
        'created_at',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'verified_at' => 'datetime',
        'created_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function prunable(): \Illuminate\Database\Eloquent\Builder
    {
        return static::where('created_at', '<', now()->subDay());
    }
}
