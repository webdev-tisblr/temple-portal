<?php

namespace Tests\Feature;

use App\Models\SystemSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /login renders, and the app-download block collapses to ONE code when a
 * universal (platform-routing) store link is configured — 2026-08-12.
 *
 * The page is a standalone Blade document with no layout, so a markup slip
 * here is a hard 500 on the one route every devotee must pass through.
 * These tests exist mostly to make that impossible to ship unnoticed.
 */
class LoginPageAppQrTest extends TestCase
{
    use RefreshDatabase;

    private function setStoreUrls(string $universal = '', string $android = '', string $ios = ''): void
    {
        SystemSetting::updateOrCreate(['key' => 'app_universal_store_url'], ['value' => $universal, 'group' => 'app']);
        SystemSetting::updateOrCreate(['key' => 'app_android_store_url'], ['value' => $android, 'group' => 'app']);
        SystemSetting::updateOrCreate(['key' => 'app_ios_store_url'], ['value' => $ios, 'group' => 'app']);
        SystemSetting::forgetCache();
    }

    public function test_login_page_renders_with_no_store_links_configured(): void
    {
        $this->setStoreUrls();

        $this->get('/login')
            ->assertOk()
            ->assertSee(route('login.otp.send'), false);
    }

    public function test_universal_link_renders_exactly_one_qr(): void
    {
        $this->setStoreUrls(
            universal: 'https://example.onelink.me/abcd/temple',
            android: 'https://play.google.com/store/apps/details?id=com.patadiyahanumanji.app',
            ios: 'https://apps.apple.com/app/patadiya-hanumanji',
        );

        $response = $this->get('/login')->assertOk();
        $html = $response->getContent();

        // One inline <svg> QR, not two — the per-store pair must not also
        // render, or the visitor is back to choosing between codes.
        $this->assertSame(
            1,
            substr_count($html, 'data-qr="app"'),
            'exactly one QR code should render when a universal link is set',
        );
        $this->assertStringContainsString('https://example.onelink.me/abcd/temple', $html);
        $this->assertStringNotContainsString('play.google.com/store/apps', $html);
    }

    public function test_exported_qr_image_is_used_when_no_universal_link_is_set(): void
    {
        // The OneLink code exported from AppsFlyer and dropped into
        // public/images/app-qr.png. Still ONE code, so a visitor is never
        // asked to work out which of two is theirs.
        $this->setStoreUrls(
            android: 'https://play.google.com/store/apps/details?id=com.patadiyahanumanji.app',
            ios: 'https://apps.apple.com/app/patadiya-hanumanji',
        );

        $html = $this->get('/login')->assertOk()->getContent();

        if (! file_exists(public_path('images/app-qr.png'))) {
            $this->markTestSkipped('No exported QR image present; the per-store pair is then correct.');
        }

        $this->assertSame(1, substr_count($html, 'data-qr="app"'), 'the exported image must render as a SINGLE code');
        $this->assertStringContainsString('images/app-qr.png', $html);

        // Phones cannot scan a code on their own screen, so the per-store
        // links must still be reachable as tappable buttons — the image
        // carries no URL we could link it to.
        $this->assertStringContainsString('play.google.com/store/apps', $html);
        $this->assertStringContainsString('apps.apple.com/app', $html);
    }

    public function test_the_setting_wins_over_the_exported_image(): void
    {
        // A generated code tracks the setting, so when both exist the
        // setting must win — otherwise editing the URL in admin would
        // silently keep serving the frozen PNG.
        $this->setStoreUrls(universal: 'https://example.onelink.me/abcd/temple');

        $html = $this->get('/login')->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, 'data-qr="app"'));
        $this->assertStringNotContainsString('images/app-qr.png', $html);
        $this->assertStringContainsString('https://example.onelink.me/abcd/temple', $html);
    }
}
