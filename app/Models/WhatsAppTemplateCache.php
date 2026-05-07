<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Local mirror of approved WhatsApp Cloud API templates. Refreshed
 * when an admin clicks "Sync templates" on the WhatsApp settings
 * page. Drives the template-name dropdown when editing a
 * NotificationTemplate whose channel = whatsapp.
 */
class WhatsAppTemplateCache extends Model
{
    protected $table = 'temple_whatsapp_templates_cache';

    protected $fillable = [
        'name',
        'language',
        'category',
        'status',
        'components',
        'meta_template_id',
        'synced_at',
    ];

    protected $casts = [
        'components' => 'array',
        'synced_at' => 'datetime',
    ];
}
