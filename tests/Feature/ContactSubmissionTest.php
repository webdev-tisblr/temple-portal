<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ContactCategory;
use App\Models\ContactSubmission;
use Database\Factories\DevoteeFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * The contact form requires a login (2026-08-17), and the sender's identity
 * comes from their profile rather than the request body — so a submission can
 * never carry a name or number the sender doesn't own.
 */
class ContactSubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // The controllers rate-limit 3/hour per devotee; several tests here
        // submit repeatedly as the same person.
        RateLimiter::clear('contact-submit:');
    }

    /** @return array<string, string> */
    private static function payload(array $overrides = []): array
    {
        return array_merge([
            'category' => ContactCategory::SUGGESTION->value,
            'subject' => 'Prasad counter timing',
            'message' => 'Please open the prasad counter earlier on Saturdays.',
        ], $overrides);
    }

    // ── web ───────────────────────────────────────────────────────────

    public function test_a_guest_cannot_submit_the_web_form(): void
    {
        $this->post('/contact', self::payload())->assertRedirect(route('login'));

        $this->assertDatabaseCount('temple_contact_submissions', 0);
    }

    public function test_the_web_page_shows_a_login_prompt_instead_of_the_form(): void
    {
        $html = $this->get('/contact')->assertOk()->getContent();

        $this->assertStringContainsString(__('contact.login_to_send'), $html);
        $this->assertStringNotContainsString('name="message"', $html);
        // The trust's own contact details stay public.
        $this->assertStringContainsString(__('contact.contact_info'), $html);
    }

    public function test_a_logged_in_devotee_sees_the_form(): void
    {
        $devotee = DevoteeFactory::new()->create(['name' => 'Bhakt Ji']);

        $html = $this->actingAs($devotee, 'devotee')->get('/contact')->assertOk()->getContent();

        $this->assertStringContainsString('name="message"', $html);
        $this->assertStringContainsString('name="category"', $html);
        // Identity is displayed, but never as a submittable field.
        $this->assertStringContainsString('Bhakt Ji', $html);
        $this->assertStringNotContainsString('name="name"', $html);
        $this->assertStringNotContainsString('name="phone"', $html);
    }

    public function test_the_web_form_takes_identity_from_the_profile(): void
    {
        $devotee = DevoteeFactory::new()->create([
            'name' => 'Bhakt Ji',
            'email' => 'bhakt@example.com',
        ]);

        $this->actingAs($devotee, 'devotee')
            ->post('/contact', self::payload())
            ->assertSessionHasNoErrors();

        $submission = ContactSubmission::sole();
        $this->assertSame($devotee->id, $submission->devotee_id);
        $this->assertSame('Bhakt Ji', $submission->name);
        $this->assertSame($devotee->phone, $submission->phone);
        $this->assertSame('bhakt@example.com', $submission->email);
        $this->assertSame(ContactCategory::SUGGESTION, $submission->category);
    }

    public function test_a_spoofed_name_and_phone_in_the_body_are_ignored(): void
    {
        $devotee = DevoteeFactory::new()->create(['name' => 'Real Devotee']);

        $this->actingAs($devotee, 'devotee')
            ->post('/contact', self::payload([
                'name' => 'Someone Else',
                'phone' => '+919999999999',
                'email' => 'attacker@example.com',
            ]))
            ->assertSessionHasNoErrors();

        $submission = ContactSubmission::sole();
        $this->assertSame('Real Devotee', $submission->name);
        $this->assertNotSame('+919999999999', $submission->phone);
        $this->assertNotSame('attacker@example.com', $submission->email);
    }

    public function test_an_unknown_category_is_refused(): void
    {
        $devotee = DevoteeFactory::new()->create();

        $this->actingAs($devotee, 'devotee')
            ->post('/contact', self::payload(['category' => 'nonsense']))
            ->assertSessionHasErrors('category');

        $this->assertDatabaseCount('temple_contact_submissions', 0);
    }

    public function test_category_defaults_to_query_when_omitted(): void
    {
        $devotee = DevoteeFactory::new()->create();
        $payload = self::payload();
        unset($payload['category']);

        $this->actingAs($devotee, 'devotee')->post('/contact', $payload);

        $this->assertSame(ContactCategory::QUERY, ContactSubmission::sole()->category);
    }

    // ── api ───────────────────────────────────────────────────────────

    public function test_the_api_refuses_an_unauthenticated_submission(): void
    {
        $this->postJson('/api/v1/contact', self::payload())->assertStatus(401);

        $this->assertDatabaseCount('temple_contact_submissions', 0);
    }

    public function test_the_api_takes_identity_from_the_token(): void
    {
        $devotee = DevoteeFactory::new()->create(['name' => 'App Bhakt']);
        Sanctum::actingAs($devotee);

        $this->postJson('/api/v1/contact', self::payload([
            'name' => 'Someone Else',
            'phone' => '+919999999999',
        ]))->assertOk();

        $submission = ContactSubmission::sole();
        $this->assertSame($devotee->id, $submission->devotee_id);
        $this->assertSame('App Bhakt', $submission->name);
        $this->assertSame($devotee->phone, $submission->phone);
    }

    public function test_the_api_publishes_the_category_list(): void
    {
        $this->getJson('/api/v1/contact-categories')
            ->assertOk()
            ->assertJsonPath('data.0.value', ContactCategory::QUERY->value)
            ->assertJsonCount(count(ContactCategory::cases()), 'data');
    }
}
