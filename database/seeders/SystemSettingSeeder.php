<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\SystemSetting;
use Illuminate\Database\Seeder;

class SystemSettingSeeder extends Seeder
{
    public function run(): void
    {
        $settings = [
            ['key' => 'trust_name', 'value' => 'Shree Pataliya Hanumanji Seva Trust', 'group' => 'trust', 'description' => 'Official trust name'],
            ['key' => 'trust_address', 'value' => 'Antarjal, Gandhidham, Kutch - 370205', 'group' => 'trust', 'description' => 'Trust registered address'],
            ['key' => 'trust_pan', 'value' => '', 'group' => 'trust', 'description' => 'Trust PAN number'],
            ['key' => 'trust_80g_reg_no', 'value' => '', 'group' => 'trust', 'description' => '80G registration number'],
            ['key' => 'trust_80g_validity', 'value' => '', 'group' => 'trust', 'description' => '80G validity period'],
            ['key' => 'trust_phone', 'value' => '+91 XXXXXXXXXX', 'group' => 'trust', 'description' => 'Trust contact phone'],
            ['key' => 'trust_email', 'value' => 'info@templeportal.in', 'group' => 'trust', 'description' => 'Trust contact email'],
            ['key' => 'youtube_live_url', 'value' => '', 'group' => 'general', 'description' => 'YouTube live stream URL'],
            ['key' => 'youtube_channel_id', 'value' => '', 'group' => 'general', 'description' => 'YouTube channel ID'],
            ['key' => 'default_language', 'value' => 'gu', 'group' => 'general', 'description' => 'Default language code'],
            ['key' => 'receipt_prefix', 'value' => 'SPHST/80G', 'group' => 'payment', 'description' => '80G receipt number prefix'],
            // Mobile app store links + install-banner toggle. Also read by the
            // /api/v1/app-config force-update endpoint.
            ['key' => 'app_install_banner_enabled', 'value' => '1', 'group' => 'app', 'description' => 'Show the "install our app" banner on the mobile website'],
            ['key' => 'app_ios_store_url', 'value' => '', 'group' => 'app', 'description' => 'Apple App Store listing URL'],
            ['key' => 'app_android_store_url', 'value' => '', 'group' => 'app', 'description' => 'Google Play Store listing URL'],
            // App Store guideline 3.2.2(iv): keep OFF until the trust is a
            // Benevity-approved nonprofit; the iOS app then donates via the
            // website. Flipping to '1' re-enables native iOS donations
            // without an app release.
            ['key' => 'app_ios_native_donations', 'value' => '0', 'group' => 'app', 'description' => 'Allow native in-app donations on iOS (only after Benevity/Apple nonprofit approval)'],
            ['key' => 'app_donate_web_url', 'value' => 'https://patadiyahanumanji.com/donate', 'group' => 'app', 'description' => 'Website donate URL the iOS app opens while native donations are disabled'],
        ];

        foreach ($settings as $setting) {
            SystemSetting::firstOrCreate(
                ['key' => $setting['key']],
                $setting
            );
        }
    }
}
