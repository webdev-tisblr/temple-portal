<?php

namespace Tests\Feature;

use App\Models\Devotee;
use App\Models\OtpCode;
use App\Support\SafeRedirect;
use Database\Factories\DevoteeFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Item 3.1 (web) — post-login redirection back to the page the devotee
 * actually wanted.
 *
 * Two ways a destination gets recorded, both consumed by
 * App\Support\SafeRedirect::intended():
 *   (a) a guest BOUNCE off a protected route — Laravel's
 *       Redirector::guest() writes session('url.intended') for free;
 *   (b) a CLICKED "log in to continue" link — no bounce happened, so the
 *       link carries ?next= (rendered by the login_url() helper).
 *
 * And the security rule that guards (b): same-host relative paths only,
 * never an auth route.
 *
 * MySQL-only project: requires the `temple_portal_test` database
 * (see phpunit.xml / CLAUDE.md).
 */
class DevoteeLoginRedirectTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '9876500011';

    /** Plant a verifiable OTP without going through the rate-limited sender. */
    private function issueOtp(string $phone = self::PHONE): string
    {
        $code = '123456';

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

    private function devotee(array $overrides = []): Devotee
    {
        return DevoteeFactory::new()->create(array_merge([
            'phone' => self::PHONE,
            'name' => 'Test Devotee',
        ], $overrides));
    }

    // ── (a) bounce → login → back to the protected page ───────────────

    public function test_guest_bounced_off_a_protected_page_returns_there_after_otp(): void
    {
        $this->devotee();

        $bounce = $this->get('/dashboard/bookings');
        $bounce->assertRedirect(route('login'));
        $this->assertSame(url('/dashboard/bookings'), session('url.intended'));

        $response = $this->post('/login/otp/verify', [
            'phone' => self::PHONE,
            'code' => $this->issueOtp(),
        ]);

        $response->assertRedirect(url('/dashboard/bookings'));
        $this->assertAuthenticated('devotee');
    }

    // ── no destination at all → dashboard ─────────────────────────────

    public function test_login_with_no_intended_url_lands_on_the_dashboard(): void
    {
        $this->devotee();

        $this->get('/login')->assertOk();

        $this->post('/login/otp/verify', [
            'phone' => self::PHONE,
            'code' => $this->issueOtp(),
        ])->assertRedirect(route('dashboard.index'));
    }

    // ── (b) clicked link carrying ?next= ──────────────────────────────

    public function test_next_query_parameter_from_a_clicked_login_link_is_honoured(): void
    {
        $this->devotee();

        $this->get('/login?next='.urlencode('/seva/hanuman-chalisa'))->assertOk();

        $this->post('/login/otp/verify', [
            'phone' => self::PHONE,
            'code' => $this->issueOtp(),
        ])->assertRedirect(url('/seva/hanuman-chalisa'));
    }

    public function test_login_url_helper_carries_a_safe_destination_and_drops_a_hostile_one(): void
    {
        $this->assertStringContainsString(
            'next=%2Fstore%2Fproduct%2Fprasad',
            SafeRedirect::loginUrl('/store/product/prasad'),
        );

        // Hostile or looping destinations are not rendered at all.
        $this->assertSame(route('login'), SafeRedirect::loginUrl('https://evil.test/x'));
        $this->assertSame(route('login'), login_url('/login'));
    }

    // ── the security rule ─────────────────────────────────────────────

    /**
     * Every one of these must be dropped on the floor: an off-host or
     * auth-route destination would be an open redirect or an infinite
     * login loop.
     *
     * @return array<string, array{0: string}>
     */
    public static function hostileNextProvider(): array
    {
        return [
            'absolute off-host' => ['https://evil.test/steal'],
            'absolute http off-host' => ['http://evil.test/steal'],
            'protocol relative' => ['//evil.test/steal'],
            'backslash escape' => ['/\\evil.test/steal'],
            'backslash pair' => ['\\\\evil.test/steal'],
            'userinfo trick' => ['https://localhost@evil.test/'],
            'auth route login' => ['/login'],
            'auth route login with query' => ['/login?next=/dashboard'],
            'auth route otp post' => ['/login/otp/verify'],
            'auth route handoff' => ['/auth/app-login?token=abc'],
            'auth route logout' => ['/logout'],
            'profile interstitial' => ['/profile/complete'],
            'not a path at all' => ['dashboard'],
            'javascript scheme' => ['javascript:alert(1)'],
        ];
    }

    /**
     * @dataProvider hostileNextProvider
     */
    public function test_hostile_next_is_rejected_and_falls_back_to_the_dashboard(string $next): void
    {
        $this->devotee();

        $this->get('/login?next='.urlencode($next))->assertOk();
        $this->assertNull(session('url.intended'), "next was accepted: {$next}");

        $this->post('/login/otp/verify', [
            'phone' => self::PHONE,
            'code' => $this->issueOtp(),
        ])->assertRedirect(route('dashboard.index'));
    }

    /**
     * @dataProvider hostileNextProvider
     */
    public function test_safe_redirect_sanitize_rejects_hostile_input(string $next): void
    {
        $this->assertNull(SafeRedirect::normalize($next), "normalize accepted: {$next}");
    }

    public function test_safe_redirect_accepts_same_host_absolute_urls_as_paths(): void
    {
        $this->assertSame('/dashboard/orders', SafeRedirect::normalize(url('/dashboard/orders')));
        $this->assertSame('/store/product/prasad?qty=2', SafeRedirect::normalize('/store/product/prasad?qty=2'));
    }

    // ── the profile-completion leg of the same chain ──────────────────

    public function test_incomplete_profile_still_continues_to_the_intended_page(): void
    {
        // A brand-new devotee has no name, so login parks them on the
        // profile form; the intended URL must survive that detour.
        $this->devotee(['name' => '', 'email' => null]);

        $this->get('/dashboard/orders')->assertRedirect(route('login'));

        $this->post('/login/otp/verify', [
            'phone' => self::PHONE,
            'code' => $this->issueOtp(),
        ])->assertRedirect(route('profile.complete'));

        $this->assertSame(url('/dashboard/orders'), session('url.intended'));

        $this->post('/profile/complete', self::completeProfilePayload())
            ->assertRedirect(url('/dashboard/orders'));
    }

    public function test_profile_completion_without_an_intended_page_lands_on_the_dashboard(): void
    {
        $devotee = $this->devotee(['name' => '', 'email' => null]);

        $this->actingAs($devotee, 'devotee')
            ->post('/profile/complete', self::completeProfilePayload())
            ->assertRedirect(route('dashboard.index'));
    }

    // ── signup requires a NAME, and only a name (2026-08-21) ──────────

    /**
     * A full payload. Every field below is still SAVED when supplied; only
     * `name` is refused when missing.
     *
     * @return array<string, string>
     */
    private static function completeProfilePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Bhakt Ji',
            'email' => 'bhakt@example.com',
            'address' => '12 Mandir Road',
            'city' => 'Bhuj',
            'state' => 'Gujarat',
            'pincode' => '370001',
        ], $overrides);
    }

    /**
     * The name is the one field a devotee cannot skip: it prints on the 80G
     * receipt and every WhatsApp template binds it — and Meta rejects an
     * entire template message when a parameter is an empty string, so a
     * nameless account received none of its messages at all.
     */
    public function test_signup_refuses_a_missing_name(): void
    {
        $devotee = $this->devotee(['name' => '', 'email' => null]);

        $this->actingAs($devotee, 'devotee')
            ->post('/profile/complete', self::completeProfilePayload(['name' => '']))
            ->assertSessionHasErrors('name');

        $this->assertSame('', (string) $devotee->fresh()->name, 'nothing should have been saved');
    }

    /**
     * …and it is the ONLY one (2026-08-21).
     *
     * Between 2026-08-17 and 2026-08-21 the address block was compulsory
     * here too. That was the wrong trade: an address is needed by the one
     * flow that ships something, which collects it at checkout, while a long
     * wall in front of someone who just wanted in is what got abandoned —
     * and an abandoned signup is precisely how the nameless accounts
     * happened. PAN is optional by consent (80G is opt-in) and email
     * because most devotees here have none.
     *
     * @return array<string, array{string}>
     */
    public static function optionalSignupFieldProvider(): array
    {
        return [
            'email' => ['email'],
            'address' => ['address'],
            'city' => ['city'],
            'state' => ['state'],
            'pincode' => ['pincode'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('optionalSignupFieldProvider')]
    public function test_signup_accepts_a_missing_optional_field(string $field): void
    {
        $devotee = $this->devotee(['name' => '', 'email' => null]);

        $this->actingAs($devotee, 'devotee')
            ->post('/profile/complete', self::completeProfilePayload([$field => '']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Bhakt Ji', $devotee->fresh()->name);
    }

    /** The minimum a devotee can get away with: a name, nothing else. */
    public function test_signup_accepts_a_name_with_nothing_else(): void
    {
        $devotee = $this->devotee(['name' => '', 'email' => null]);

        $this->actingAs($devotee, 'devotee')
            ->post('/profile/complete', ['name' => 'Bhakt Ji'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Bhakt Ji', $devotee->fresh()->name);
    }

    public function test_pan_and_email_stay_optional_at_signup(): void
    {
        $devotee = $this->devotee(['name' => '', 'email' => null]);

        // No PAN, and email left blank — both are accepted.
        $this->actingAs($devotee, 'devotee')
            ->post('/profile/complete', self::completeProfilePayload(['email' => '']))
            ->assertSessionHasNoErrors();

        $this->assertSame('Bhakt Ji', $devotee->fresh()->name);
    }

    public function test_the_signup_form_does_not_label_email_optional(): void
    {
        // Accepted blank, but never advertised as skippable — and no asterisk
        // either, which would claim it is enforced.
        $devotee = $this->devotee(['name' => '', 'email' => null]);

        $html = $this->actingAs($devotee, 'devotee')
            ->get('/profile/complete')->assertOk()->getContent();

        $emailLabel = Str::before(Str::after($html, 'for="cp_email"'), '</label>');
        $this->assertStringNotContainsString(__('store.optional'), $emailLabel);
        $this->assertStringNotContainsString('*', $emailLabel);
    }

    /** @return array<string, array{string}> */
    public static function badPincodeProvider(): array
    {
        return [
            'too short' => ['37020'],
            'too long' => ['3702011'],
            'leading zero' => ['070201'],
            'letters' => ['37020A'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('badPincodeProvider')]
    public function test_signup_refuses_a_malformed_pincode(string $pincode): void
    {
        $devotee = $this->devotee(['name' => '', 'email' => null]);

        $this->actingAs($devotee, 'devotee')
            ->post('/profile/complete', self::completeProfilePayload(['pincode' => $pincode]))
            ->assertSessionHasErrors('pincode');
    }

    public function test_signup_refuses_a_malformed_email(): void
    {
        $devotee = $this->devotee(['name' => '', 'email' => null]);

        $this->actingAs($devotee, 'devotee')
            ->post('/profile/complete', self::completeProfilePayload(['email' => 'not-an-email']))
            ->assertSessionHasErrors('email');
    }
}
