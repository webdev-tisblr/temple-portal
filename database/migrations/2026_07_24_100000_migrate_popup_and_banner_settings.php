<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Popup → carousel + app-install banner controls into Home Page Settings.
 *
 * 1. Fold the legacy single popup (site_popup_image/title/body/cta_*) into
 *    the new site_popup_slides JSON array as slide #1, so nothing is lost.
 * 2. Seed the new cooldown/schedule keys with sensible defaults.
 * 3. Migrate the old app_install_banner_enabled flag to site_banner_enabled.
 *    Store URLs (app_ios_store_url / app_android_store_url) stay put — they
 *    also feed the app force-update check.
 */
return new class extends Migration
{
    private function get(string $key, string $default = ''): string
    {
        $row = DB::table('temple_system_settings')->where('key', $key)->value('value');

        return $row === null ? $default : (string) $row;
    }

    private function put(string $key, string $value, string $group = 'website'): void
    {
        DB::table('temple_system_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $value, 'group' => $group, 'updated_at' => now()],
        );
    }

    public function up(): void
    {
        // 1. Legacy single popup → slides[0] (only if no slides yet).
        $existingSlides = $this->get('site_popup_slides');
        if ($existingSlides === '' || $existingSlides === '[]') {
            $legacy = [
                'image' => $this->get('site_popup_image'),
                'title_gu' => $this->get('site_popup_title_gu'),
                'title_hi' => $this->get('site_popup_title_hi'),
                'title_en' => $this->get('site_popup_title_en'),
                'body_gu' => $this->get('site_popup_body_gu'),
                'body_hi' => $this->get('site_popup_body_hi'),
                'body_en' => $this->get('site_popup_body_en'),
                'cta_label_gu' => $this->get('site_popup_cta_label_gu'),
                'cta_label_hi' => $this->get('site_popup_cta_label_hi'),
                'cta_label_en' => $this->get('site_popup_cta_label_en'),
                'cta_url' => $this->get('site_popup_cta_url'),
            ];

            $hasContent = $legacy['image'] !== '' || $legacy['title_gu'] !== ''
                || $legacy['title_hi'] !== '' || $legacy['title_en'] !== ''
                || $legacy['body_gu'] !== '' || $legacy['body_hi'] !== '' || $legacy['body_en'] !== '';

            $this->put('site_popup_slides', $hasContent ? json_encode([$legacy]) : '[]');
        }

        // 2. Popup cooldown default: once per day.
        if ($this->get('site_popup_cooldown_days') === '') {
            $this->put('site_popup_cooldown_days', '1');
        }

        // 3. Banner controls into the site_* space.
        // Preserve the previous enabled flag (System Settings default was ON).
        $bannerEnabled = $this->get('app_install_banner_enabled', '1');
        if ($this->get('site_banner_enabled') === '') {
            $this->put('site_banner_enabled', $bannerEnabled === '1' ? '1' : '0');
        }
        if ($this->get('site_banner_cooldown_days') === '') {
            $this->put('site_banner_cooldown_days', '14');
        }
        if ($this->get('site_banner_delay_seconds') === '') {
            $this->put('site_banner_delay_seconds', '2');
        }
    }

    public function down(): void
    {
        // One-way consolidation; the legacy keys are left intact so nothing
        // to restore. No-op.
    }
};
