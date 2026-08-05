<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

/**
 * A shared booking-capacity pool. Member sevas (temple_sevas.slot_pool_id)
 * all follow THIS pool's slot_config — one schedule, one capacity — and
 * their bookings are counted together by SevaSlotService.
 */
class SevaSlotPool extends Model
{
    protected $table = 'temple_seva_slot_pools';

    protected $fillable = [
        'name',
        'slot_config',
    ];

    protected $casts = [
        'slot_config' => 'array',
    ];

    protected static function booted(): void
    {
        // Pool slot settings feed the public seva availability payloads.
        $bust = fn () => Cache::forget('active_sevas');
        static::saved($bust);
        static::deleted($bust);
    }

    public function sevas(): HasMany
    {
        return $this->hasMany(Seva::class, 'slot_pool_id');
    }
}
