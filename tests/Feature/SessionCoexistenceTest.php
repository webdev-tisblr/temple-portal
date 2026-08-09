<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Models\Devotee;
use App\Models\OtpCode;
use Database\Factories\DevoteeFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Spec 07, suspect #2 — "the app randomly terminates my session".
 *
 * Devotee::revokeOtherLogins() used to be global: any web OTP login
 * deleted EVERY Sanctum token, including the phone's, and detached the
 * FCM rows. Because iOS donations are forced onto the website
 * (App Store 3.2.2(iv)), donating logged the devotee out of the app.
 *
 * The rule is now per surface:
 *   • web login  → bump auth_epoch (other BROWSER sessions die), app
 *                  tokens + FCM registrations untouched;
 *   • app login  → delete other 'mobile-app' tokens + detach FCM (a
 *                  second phone still evicts the first), web sessions
 *                  untouched;
 *   • /auth/app-login handoff → revokes nothing at all; it is the same
 *     login lineage, and it is the exact path the iOS donation uses.
 *
 * MySQL-only project: requires the `temple_portal_test` database
 * (see phpunit.xml / CLAUDE.md).
 */
class SessionCoexistenceTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '9876500022';

    private function issueOtp(string $phone = self::PHONE): string
    {
        $code = '654321';

        OtpCode::create([
            'phone' => $phone,
            'code' => $code,
            'purpose' => 'login',
            'attempts' => 0,
            'expires_at' => now()->addMinutes(10),
            'created_at' => now(),
        ]);

        return $code;
    }

    private function devotee(): Devotee
    {
        return DevoteeFactory::new()->create([
            'phone' => self::PHONE,
            'name' => 'Test Devotee',
        ]);
    }

    private function appToken(Devotee $devotee): string
    {
        return $devotee->createToken(Devotee::APP_TOKEN_NAME)->plainTextToken;
    }

    // ── web login must not touch the app ──────────────────────────────

    public function test_web_login_does_not_revoke_an_existing_app_token(): void
    {
        $devotee = $this->devotee();
        $appToken = $this->appToken($devotee);

        DeviceToken::create([
            'devotee_id' => $devotee->id,
            'token' => 'fcm-token-phone-1',
            'platform' => 'android',
            'is_active' => true,
        ]);

        $this->post('/login/otp/verify', [
            'phone' => self::PHONE,
            'code' => $this->issueOtp(),
        ])->assertRedirect();

        $this->assertSame(1, $devotee->tokens()->count(), 'the web login deleted the phone token');

        // The phone must still be able to call the API.
        $this->withHeader('Authorization', 'Bearer '.$appToken)
            ->getJson('/api/v1/me')
            ->assertOk();

        // …and must still be reachable by devotee-targeted pushes.
        $this->assertDatabaseHas('temple_device_tokens', [
            'token' => 'fcm-token-phone-1',
            'devotee_id' => $devotee->id,
        ]);
    }

    public function test_web_login_still_evicts_other_web_sessions(): void
    {
        $devotee = $this->devotee();
        $epochBefore = (int) $devotee->auth_epoch;

        $this->post('/login/otp/verify', [
            'phone' => self::PHONE,
            'code' => $this->issueOtp(),
        ])->assertRedirect();

        $this->assertSame($epochBefore + 1, (int) $devotee->fresh()->auth_epoch);
    }

    public function test_a_web_session_stamped_with_a_stale_epoch_is_logged_out(): void
    {
        $devotee = $this->devotee();
        $devotee->forceFill(['auth_epoch' => 5])->save();

        $this->withSession(['devotee_auth_epoch' => 4])
            ->actingAs($devotee, 'devotee')
            ->get('/dashboard')
            ->assertRedirect(route('login'));
    }

    // ── app login must not touch the web ──────────────────────────────

    public function test_app_login_on_a_second_device_revokes_the_first_app_token(): void
    {
        $devotee = $this->devotee();
        $firstPhone = $devotee->createToken(Devotee::APP_TOKEN_NAME);

        $response = $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => self::PHONE,
            'code' => $this->issueOtp(),
        ])->assertOk();

        $this->assertDatabaseMissing('personal_access_tokens', [
            'id' => $firstPhone->accessToken->getKey(),
        ]);

        // The first phone's bearer no longer authenticates…
        $this->withHeader('Authorization', 'Bearer '.$firstPhone->plainTextToken)
            ->getJson('/api/v1/me')
            ->assertUnauthorized();

        // …while the second phone's does.
        $this->withHeader('Authorization', 'Bearer '.$response->json('data.token'))
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    public function test_app_login_does_not_evict_web_sessions(): void
    {
        $devotee = $this->devotee();
        $epochBefore = (int) $devotee->auth_epoch;

        $this->postJson('/api/v1/auth/otp/verify', [
            'phone' => self::PHONE,
            'code' => $this->issueOtp(),
        ])->assertOk();

        $this->assertSame(
            $epochBefore,
            (int) $devotee->fresh()->auth_epoch,
            'an app login bumped auth_epoch and killed the browser session',
        );
    }

    // ── the iOS donation handoff ──────────────────────────────────────

    public function test_app_login_handoff_leaves_the_app_token_intact(): void
    {
        $devotee = $this->devotee();
        $appToken = $this->appToken($devotee);

        $link = $this->withHeader('Authorization', 'Bearer '.$appToken)
            ->postJson('/api/v1/auth/web-session-token', ['redirect_to' => '/donate'])
            ->assertOk()
            ->json('data.url');

        $handoffToken = (string) parse_url((string) $link, PHP_URL_QUERY);
        parse_str($handoffToken, $query);

        $this->get('/auth/app-login?token='.$query['token'])
            ->assertRedirect('/donate');

        $this->assertAuthenticated('devotee');

        // The browser session opened, the phone kept its token, and the
        // handoff revoked nothing at all (epoch untouched).
        $this->assertSame(1, $devotee->tokens()->count());
        $this->assertSame(0, (int) $devotee->fresh()->auth_epoch);

        $this->withHeader('Authorization', 'Bearer '.$appToken)
            ->getJson('/api/v1/me')
            ->assertOk();
    }

    // ── the explicit "everywhere" scope still nukes everything ────────

    public function test_all_scope_still_revokes_both_surfaces(): void
    {
        $devotee = $this->devotee();
        $this->appToken($devotee);

        DeviceToken::create([
            'devotee_id' => $devotee->id,
            'token' => 'fcm-token-phone-2',
            'platform' => 'ios',
            'is_active' => true,
        ]);

        $devotee->revokeOtherLogins(Devotee::SCOPE_ALL);

        $this->assertSame(0, $devotee->tokens()->count());
        $this->assertSame(1, (int) $devotee->fresh()->auth_epoch);
        $this->assertDatabaseHas('temple_device_tokens', [
            'token' => 'fcm-token-phone-2',
            'devotee_id' => null,
        ]);
    }
}
