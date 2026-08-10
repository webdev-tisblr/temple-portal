<?php

declare(strict_types=1);

namespace Tests\Feature;

use Database\Factories\DevoteeFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * /logout used to 404 on GET (a devotee typing the URL) and 419 on a POST
 * with a stale token — in both cases leaving them on an error page, still
 * apparently signed in, with no way to finish signing out.
 */
class LogoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_typing_the_logout_url_signs_a_devotee_out(): void
    {
        $devotee = DevoteeFactory::new()->create();

        $this->actingAs($devotee, 'devotee')
            ->get('/logout')
            ->assertRedirect(route('home'));

        $this->assertGuest('devotee');
    }

    public function test_typing_the_logout_url_as_a_guest_just_goes_home(): void
    {
        $this->get('/logout')->assertRedirect(route('home'));
        $this->assertGuest('devotee');
    }

    public function test_the_post_logout_still_works(): void
    {
        $devotee = DevoteeFactory::new()->create();

        $this->actingAs($devotee, 'devotee')
            ->post('/logout')
            ->assertRedirect(route('home'));

        $this->assertGuest('devotee');
    }

    public function test_a_stale_token_on_logout_completes_the_logout_instead_of_419(): void
    {
        $devotee = DevoteeFactory::new()->create();

        // Re-enable the CSRF middleware that the test harness disables, so a
        // tokenless POST really does raise TokenMismatchException.
        $this->withMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class);

        $this->actingAs($devotee, 'devotee')
            ->post('/logout', [])
            ->assertRedirect(route('home'));

        $this->assertGuest('devotee');
    }
}
