<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SystemSetting extends Model
{
    protected $table = 'temple_system_settings';

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'key',
        'value',
        'group',
        'description',
        'updated_by',
        'updated_at',
    ];

    protected $casts = [
        'updated_at' => 'datetime',
    ];

    public static function getValue(string $key, string $default = ''): string
    {
        return static::where('key', $key)->value('value') ?? $default;
    }

    public static function setValue(string $key, string $value): void
    {
        static::updateOrCreate(
            ['key' => $key],
            ['value' => $value, 'updated_at' => now()]
        );
    }
}
