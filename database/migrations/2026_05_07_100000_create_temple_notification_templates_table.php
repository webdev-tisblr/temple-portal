<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * notification_templates — single source of truth for every email,
 * WhatsApp and push notification fired across the platform.
 *
 * Every domain trigger has a unique `key` (e.g. "donation.confirmed").
 * Multiple rows can share a key — one per channel — so a single trigger
 * can fan out to email + WhatsApp + push, each independently togglable.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::create('temple_notification_templates', function (Blueprint $table) {
            $table->id();

            // Trigger identity — many rows can share a key, distinguished by channel.
            $table->string('key', 100)
                ->comment('Domain trigger e.g. donation.confirmed, hall.booking.confirmed');
            $table->string('label')->comment('Human-readable name shown in admin');
            $table->text('description')->nullable();

            $table->enum('channel', ['email', 'whatsapp', 'push']);
            $table->boolean('is_enabled')->default(true);

            // ── Email-only fields ──────────────────────────────────────────
            $table->string('subject')->nullable()
                ->comment('Email subject; supports {{ placeholder }} substitutions');
            $table->longText('body')->nullable()
                ->comment('Email HTML body; supports {{ placeholder }} substitutions');
            $table->string('from_name')->nullable()
                ->comment('Override sender name; falls back to mail_from_name');
            $table->string('from_address')->nullable()
                ->comment('Override sender email; falls back to mail_from_address');

            // ── WhatsApp-only fields ───────────────────────────────────────
            $table->string('wa_template_name')->nullable()
                ->comment('Approved WhatsApp template name (from synced cache)');
            $table->string('wa_template_language', 10)->nullable()
                ->comment('Template locale, e.g. en or gu_IN');
            $table->json('wa_components')->nullable()
                ->comment('Header/body/button param mapping per WA Cloud API spec');

            // ── Push-only fields ───────────────────────────────────────────
            $table->json('push_title')->nullable()
                ->comment('Per-locale push title { gu, hi, en }');
            $table->json('push_body')->nullable()
                ->comment('Per-locale push body { gu, hi, en }');
            $table->string('push_deep_link')->nullable()
                ->comment('Optional in-app deep link to open on tap');

            // ── Recipient resolution ───────────────────────────────────────
            $table->enum('recipient_strategy', [
                'devotee',          // pull from $context['devotee']
                'trust_admin',      // resolved from trust_email / trust_phone setting
                'fixed_email',
                'fixed_phone',
                'context_path',     // dot-path inside $context, e.g. "booking.contact_email"
            ])->default('devotee');
            $table->string('recipient_value')->nullable()
                ->comment('Address for fixed_* OR dot-path for context_path');

            // Mapping of {{ placeholder }} → dot-path inside the dispatched context.
            // e.g. { "amount": "donation.amount", "name": "donation.devotee.name" }
            $table->json('placeholder_map')->nullable();

            $table->timestamps();

            $table->unique(['key', 'channel']);
            $table->index('channel');
            $table->index('is_enabled');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('temple_notification_templates');
    }
};
