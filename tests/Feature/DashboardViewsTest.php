<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Devotee;
use App\Models\Receipt80G;
use Database\Factories\DevoteeFactory;
use Database\Factories\DonationFactory;
use Database\Factories\HallBookingFactory;
use Database\Factories\HallFactory;
use Database\Factories\OrderFactory;
use Database\Factories\OrderItemFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\SevaBookingFactory;
use Database\Factories\SevaFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Item 2.6 — the devotee dashboard redesign.
 *
 * Three things are worth a test here, and they are not the pixels:
 *
 *  1. All seven views RENDER. They were rewritten wholesale onto new shared
 *     components (<x-dashboard.*>), and a Blade typo is a 500, not a warning.
 *  2. The captured-payments-only rule still holds on every list. A dashboard
 *     that shows an abandoned Razorpay handoff as a real donation is a
 *     serious bug — the redesign must not have widened a single query.
 *  3. The new hall-bookings panel prints a DATE RANGE. Multi-day hall
 *     bookings landed in the same batch (booking_date is the range start,
 *     end_date the last day); showing only the start would understate a
 *     three-day reservation.
 *
 * MySQL only — requires the `temple_portal_test` database (see CLAUDE.md).
 */
class DashboardViewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // These are VIEW tests: they need Payment rows to exist so the
        // captured-only filters have something to filter, and they do not
        // care about the audit trail. Switching the activity log off keeps
        // the fixtures cheap — and, at the time of writing, side-steps an
        // unrelated in-flight defect: App\Models\Payment gained
        // Spatie LogsActivity while keeping its UUID primary key, but
        // activity_log.subject_id is an unsignedBigInteger
        // (2026_03_30_080936_create_activity_log_table.php:15,
        // `nullableMorphs('subject')`), so every Payment insert now throws
        // SQLSTATE[HY000] 1366. See the report accompanying item 2.6.
        config(['activitylog.enabled' => false]);
    }

    /**
     * Hall::$name is a localized accessor that reads name_gu / name_hi /
     * name_en before falling back to the legacy `name` column, and the
     * dashboard renders in the devotee's language (gu by default). Setting
     * only `name` would leave the panel showing the factory's random words.
     *
     * @return array<string, string>
     */
    private function hallName(string $name): array
    {
        return ['name' => $name, 'name_gu' => $name, 'name_hi' => $name, 'name_en' => $name];
    }

    private function devotee(array $overrides = []): Devotee
    {
        return DevoteeFactory::new()->create(array_merge([
            'name' => 'Test Devotee',
        ], $overrides));
    }

    // ── 1. Every view renders ────────────────────────────────────────

    public function test_all_seven_dashboard_views_render_for_a_logged_in_devotee(): void
    {
        $devotee = $this->devotee();

        foreach ([
            'dashboard.index',
            'dashboard.donations',
            'dashboard.bookings',
            'dashboard.orders',
            'dashboard.receipts',
            'dashboard.profile',
        ] as $routeName) {
            $this->actingAs($devotee, 'devotee')
                ->get(route($routeName))
                ->assertOk();
        }

        // The seventh view is the onboarding gate. It only renders for a
        // devotee who has not given a name yet — otherwise the controller
        // forwards them on (item 3.1's SafeRedirect).
        $newcomer = $this->devotee(['name' => '']);

        $this->actingAs($newcomer, 'devotee')
            ->get(route('profile.complete'))
            ->assertOk()
            ->assertSee(__('dashboard.complete_profile'))
            ->assertSee(__('dashboard.pan_disclaimer'));
    }

    public function test_every_list_view_renders_when_the_devotee_has_no_records(): void
    {
        $devotee = $this->devotee();

        foreach (['dashboard.index', 'dashboard.donations', 'dashboard.bookings', 'dashboard.orders', 'dashboard.receipts'] as $routeName) {
            $this->actingAs($devotee, 'devotee')->get(route($routeName))->assertOk();
        }

        $this->actingAs($devotee, 'devotee')
            ->get(route('dashboard.bookings'))
            ->assertSee(__('dashboard.no_seva_bookings'))
            ->assertSee(__('dashboard.no_hall_bookings'));
    }

    // ── 2. Captured payments only ────────────────────────────────────

    public function test_donations_list_shows_captured_payments_and_hides_pending_ones(): void
    {
        $devotee = $this->devotee();

        DonationFactory::new()->create([
            'devotee_id' => $devotee->id,
            'payment_id' => PaymentFactory::new()->captured()->create()->id,
            'amount' => 11111,
        ]);

        DonationFactory::new()->create([
            'devotee_id' => $devotee->id,
            // Default PaymentFactory status is 'created' — the pre-capture
            // scratch state a devotee leaves behind by closing the Razorpay
            // modal.
            'payment_id' => PaymentFactory::new()->create()->id,
            'amount' => 22222,
        ]);

        $response = $this->actingAs($devotee, 'devotee')->get(route('dashboard.donations'));

        $response->assertOk()
            ->assertSee('11,111')
            ->assertDontSee('22,222');
    }

    public function test_overview_totals_and_recent_lists_count_captured_payments_only(): void
    {
        $devotee = $this->devotee();

        DonationFactory::new()->create([
            'devotee_id' => $devotee->id,
            'payment_id' => PaymentFactory::new()->captured()->create()->id,
            'amount' => 11111,
        ]);
        DonationFactory::new()->create([
            'devotee_id' => $devotee->id,
            'payment_id' => PaymentFactory::new()->create()->id,
            'amount' => 22222,
        ]);

        $response = $this->actingAs($devotee, 'devotee')->get(route('dashboard.index'));

        $response->assertOk()
            // Total donated must be the captured 11,111 alone, not 33,333.
            ->assertSee('₹11,111')
            ->assertDontSee('22,222')
            ->assertDontSee('₹33,333');
    }

    public function test_seva_bookings_list_hides_bookings_whose_payment_never_captured(): void
    {
        $devotee = $this->devotee();
        $seva = SevaFactory::new()->create();

        SevaBookingFactory::new()->create([
            'devotee_id' => $devotee->id,
            'seva_id' => $seva->id,
            'payment_id' => PaymentFactory::new()->captured()->create()->id,
            'status' => 'confirmed',
            // Also pins the fix for a long-standing display bug: the old
            // view read $booking->amount, a column that does not exist, so
            // every paid seva booking rendered as ₹0.
            'total_amount' => 33333,
        ]);

        SevaBookingFactory::new()->create([
            'devotee_id' => $devotee->id,
            'seva_id' => $seva->id,
            'payment_id' => PaymentFactory::new()->create()->id,
            'total_amount' => 44444,
        ]);

        $this->actingAs($devotee, 'devotee')
            ->get(route('dashboard.bookings'))
            ->assertOk()
            ->assertSee('33,333')
            ->assertDontSee('44,444');
    }

    public function test_orders_list_hides_abandoned_checkouts(): void
    {
        $devotee = $this->devotee();

        $captured = OrderFactory::new()->create([
            'devotee_id' => $devotee->id,
            'payment_id' => PaymentFactory::new()->captured()->create()->id,
            'order_number' => 'ORD-CAPTURED-1',
            'status' => 'confirmed',
        ]);
        OrderItemFactory::new()->create(['order_id' => $captured->id]);

        $abandoned = OrderFactory::new()->create([
            'devotee_id' => $devotee->id,
            'payment_id' => PaymentFactory::new()->create()->id,
            'order_number' => 'ORD-PENDING-1',
        ]);
        OrderItemFactory::new()->create(['order_id' => $abandoned->id]);

        $this->actingAs($devotee, 'devotee')
            ->get(route('dashboard.orders'))
            ->assertOk()
            ->assertSee('ORD-CAPTURED-1')
            ->assertDontSee('ORD-PENDING-1');
    }

    public function test_80g_receipts_list_only_covers_captured_donations(): void
    {
        $devotee = $this->devotee();

        $capturedDonation = DonationFactory::new()->create([
            'devotee_id' => $devotee->id,
            'payment_id' => PaymentFactory::new()->captured()->create()->id,
            'amount' => 5000,
        ]);
        $pendingDonation = DonationFactory::new()->create([
            'devotee_id' => $devotee->id,
            'payment_id' => PaymentFactory::new()->create()->id,
            'amount' => 5000,
        ]);

        Receipt80G::create([
            'donation_id' => $capturedDonation->id,
            'receipt_number' => 'RCPT-CAPTURED-1',
            'financial_year' => '2026-27',
            'devotee_name' => $devotee->name,
            'pan_number' => 'ABCDE1234F',
            'amount' => 5000,
            'amount_in_words' => 'Five Thousand Rupees Only',
            'donation_date' => now()->toDateString(),
            'payment_mode' => 'online',
        ]);
        Receipt80G::create([
            'donation_id' => $pendingDonation->id,
            'receipt_number' => 'RCPT-PENDING-1',
            'financial_year' => '2026-27',
            'devotee_name' => $devotee->name,
            'pan_number' => 'ABCDE1234F',
            'amount' => 5000,
            'amount_in_words' => 'Five Thousand Rupees Only',
            'donation_date' => now()->toDateString(),
            'payment_mode' => 'online',
        ]);

        $this->actingAs($devotee, 'devotee')
            ->get(route('dashboard.receipts'))
            ->assertOk()
            ->assertSee('RCPT-CAPTURED-1')
            ->assertDontSee('RCPT-PENDING-1');
    }

    // ── 3. The new hall-bookings panel ───────────────────────────────

    public function test_hall_panel_prints_a_multi_day_range_not_just_the_start_date(): void
    {
        $devotee = $this->devotee();
        $hall = HallFactory::new()->multiDay(7)->create($this->hallName('Satsang Hall'));

        HallBookingFactory::new()
            ->forHall($hall)
            ->range('2026-09-12', '2026-09-14')
            ->create([
                'devotee_id' => $devotee->id,
                'payment_id' => PaymentFactory::new()->captured()->create()->id,
                'status' => 'confirmed',
                'total_amount' => 15000,
            ]);

        $response = $this->actingAs($devotee, 'devotee')->get(route('dashboard.bookings'));

        $response->assertOk()
            ->assertSee(__('dashboard.hall_bookings'))
            ->assertSee('Satsang Hall')
            // The whole point: a range, not "12/09/2026" on its own.
            ->assertSee('12 – 14 Sep 2026')
            ->assertSee(trans_choice('dashboard.days_count', 3, ['count' => 3]))
            ->assertSee('15,000');
    }

    public function test_hall_panel_prints_a_single_date_for_a_one_day_booking(): void
    {
        $devotee = $this->devotee();
        $hall = HallFactory::new()->create($this->hallName('Bhojan Hall'));

        HallBookingFactory::new()
            ->forHall($hall)
            ->range('2026-09-20', '2026-09-20')
            ->create([
                'devotee_id' => $devotee->id,
                'payment_id' => PaymentFactory::new()->captured()->create()->id,
                'status' => 'confirmed',
            ]);

        $this->actingAs($devotee, 'devotee')
            ->get(route('dashboard.bookings'))
            ->assertOk()
            ->assertSee('20 Sep 2026')
            ->assertSee(trans_choice('dashboard.days_count', 1, ['count' => 1]));
    }

    public function test_hall_panel_hides_a_booking_whose_payment_never_captured(): void
    {
        $devotee = $this->devotee();
        $hall = HallFactory::new()->create($this->hallName('Ghost Hall'));

        HallBookingFactory::new()
            ->forHall($hall)
            ->create([
                'devotee_id' => $devotee->id,
                'payment_id' => PaymentFactory::new()->create()->id,
            ]);

        $this->actingAs($devotee, 'devotee')
            ->get(route('dashboard.bookings'))
            ->assertOk()
            ->assertDontSee('Ghost Hall')
            ->assertSee(__('dashboard.no_hall_bookings'));
    }

    // ── 4. Things the redesign must not have dropped ─────────────────

    public function test_profile_view_keeps_the_pan_field_disclaimer_and_photo_upload(): void
    {
        $devotee = $this->devotee();

        $this->actingAs($devotee, 'devotee')
            ->get(route('dashboard.profile'))
            ->assertOk()
            ->assertSee('name="pan_number"', false)
            ->assertSee('name="profile_photo"', false)
            ->assertSee('enctype="multipart/form-data"', false)
            ->assertSee(e(__('dashboard.pan_disclaimer')), false);
    }

    public function test_profile_view_offers_pan_removal_only_when_a_pan_is_stored(): void
    {
        $withoutPan = $this->devotee();

        $this->actingAs($withoutPan, 'devotee')
            ->get(route('dashboard.profile'))
            ->assertOk()
            ->assertDontSee('name="clear_pan"', false);

        $withPan = DevoteeFactory::new()->withPan()->create(['name' => 'Panned Devotee']);

        $this->actingAs($withPan, 'devotee')
            ->get(route('dashboard.profile'))
            ->assertOk()
            ->assertSee('name="clear_pan"', false)
            ->assertSee(e(__('dashboard.clear_pan')), false);
    }

    public function test_profile_view_explains_why_a_donor_was_sent_here_for_a_pan(): void
    {
        $devotee = $this->devotee();

        $this->actingAs($devotee, 'devotee')
            ->withSession(['pan_required_for_80g' => true])
            ->get(route('dashboard.profile'))
            ->assertOk()
            ->assertSee(e(__('dashboard.pan_needed_banner')), false);
    }

    public function test_dashboard_table_headers_are_translated_not_transliterated(): void
    {
        $devotee = $this->devotee();

        // The old views shipped bare "Tarikh" / "Rakam" / "Daan Tarikh" /
        // "Financial Year" strings straight in the Blade.
        $this->actingAs($devotee, 'devotee')
            ->get(route('dashboard.bookings'))
            ->assertOk()
            ->assertDontSee('Tarikh')
            ->assertDontSee('Rakam')
            ->assertDontSee('Samay (Slot)')
            ->assertDontSee('Seva Book karo');

        $this->actingAs($devotee, 'devotee')
            ->get(route('dashboard.receipts'))
            ->assertOk()
            ->assertDontSee('Daan Tarikh')
            ->assertDontSee('Rakam');
    }

    public function test_orders_view_uses_the_shared_page_header(): void
    {
        $devotee = $this->devotee();

        $this->actingAs($devotee, 'devotee')
            ->get(route('dashboard.orders'))
            ->assertOk()
            // <x-page-header> renders the title inside .divine-heading; the
            // hand-rolled breadcrumb this page used to carry did not.
            ->assertSee('divine-heading');
    }

    /**
     * The rendered page still contains the shared header/footer, which have
     * not been rewritten yet, so this asserts about the SOURCE of the views
     * we own. It is the regression guard for the app.css compatibility
     * layer: if a legacy dark-theme class creeps back into the dashboard,
     * the rules that were deleted from that layer are gone and the page
     * will quietly render dark-on-parchment.
     */
    public function test_dashboard_view_sources_carry_no_legacy_dark_theme_classes(): void
    {
        $legacyClasses = [
            'text-amber-100', 'text-amber-200', 'text-amber-300', 'text-amber-400',
            'text-amber-500', 'text-amber-600', 'text-amber-800',
            'bg-amber-900', 'bg-amber-950', 'bg-amber-800',
            'border-amber-700', 'border-amber-800', 'border-amber-900',
            'bg-emerald-950', 'bg-emerald-900', 'text-emerald-300', 'text-emerald-400',
            'bg-red-950', 'bg-red-900', 'border-red-800', 'text-red-300', 'text-red-400',
            'bg-blue-950', 'text-blue-300', 'text-blue-400',
            'text-gold', 'bg-gold',
        ];

        $files = array_merge(
            glob(resource_path('views/pages/dashboard/*.blade.php')) ?: [],
            glob(resource_path('views/components/dashboard/*.blade.php')) ?: [],
        );

        $this->assertNotEmpty($files, 'No dashboard views found — did they move?');

        foreach ($files as $file) {
            $source = (string) file_get_contents($file);

            foreach ($legacyClasses as $legacyClass) {
                $this->assertStringNotContainsString(
                    $legacyClass,
                    $source,
                    basename($file).' still uses the legacy class "'.$legacyClass.'", which the app.css compatibility layer no longer covers.'
                );
            }
        }
    }
}
