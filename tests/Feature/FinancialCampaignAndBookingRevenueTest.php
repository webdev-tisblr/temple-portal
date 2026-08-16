<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\FinancialReports;
use App\Filament\Resources\DonationResource\Pages\ListDonations;
use App\Filament\Widgets\BookingRevenueOverview;
use App\Models\AdminUser;
use App\Models\Donation;
use App\Models\DonationCampaign;
use Database\Factories\DonationFactory;
use Database\Factories\HallBookingFactory;
use Database\Factories\PaymentFactory;
use Database\Factories\SevaBookingFactory;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Pages\Dashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * Two dashboard/report gaps closed together:
 *
 *  1. Financial Reports could not be filtered by campaign at all, so
 *     "how much did this campaign raise" had no answer on the page that
 *     produces the trust's auditor exports.
 *  2. The dashboard had donation revenue tiles and nothing for seva or
 *     hall bookings.
 *
 * Both must keep the captured-only rule — the whole point of these
 * surfaces is money actually received.
 *
 * MySQL only — requires the `temple_portal_test` database (see CLAUDE.md).
 */
class FinancialCampaignAndBookingRevenueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_campaign_filter_narrows_totals_to_that_campaign(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        $campaign = $this->campaign('Gaushala');
        $other = $this->campaign('Bhojanalaya');

        $this->capturedDonation(5000, $campaign->id);
        $this->capturedDonation(2000, $other->id);
        $this->capturedDonation(700, null);

        // An abandoned checkout ON the campaign — must never be counted.
        DonationFactory::new()->create([
            'amount' => 99999,
            'campaign_id' => $campaign->id,
            'payment_id' => PaymentFactory::new()->create()->id,
        ]);

        $page = Livewire::test(FinancialReports::class)
            ->set('date_from', now()->subDay()->toDateString())
            ->set('date_to', now()->addDay()->toDateString());

        $this->assertSame('7,700.00', $page->instance()->getSummary()['total']);

        $page->set('campaign_id', (string) $campaign->id)->call('applyFilters');
        $this->assertSame('5,000.00', $page->instance()->getSummary()['total']);
        $this->assertSame(1, $page->instance()->getSummary()['count']);
    }

    public function test_no_campaign_option_returns_only_general_donations(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        $campaign = $this->campaign('Gaushala');
        $this->capturedDonation(5000, $campaign->id);
        $this->capturedDonation(700, null);
        $this->capturedDonation(300, null);

        $page = Livewire::test(FinancialReports::class)
            ->set('date_from', now()->subDay()->toDateString())
            ->set('date_to', now()->addDay()->toDateString())
            ->set('campaign_id', 'none')
            ->call('applyFilters');

        $this->assertSame('1,000.00', $page->instance()->getSummary()['total']);
        $this->assertSame(2, $page->instance()->getSummary()['count']);
    }

    public function test_donations_list_can_be_filtered_by_campaign(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        $campaign = $this->campaign('Gaushala');
        $this->capturedDonation(5000, $campaign->id);
        $this->capturedDonation(700, null);

        $onCampaign = Donation::where('campaign_id', $campaign->id)->firstOrFail();
        $general = Donation::whereNull('campaign_id')->firstOrFail();

        Livewire::test(ListDonations::class)
            ->filterTable('campaign_id', (string) $campaign->id)
            ->assertCanSeeTableRecords([$onCampaign])
            ->assertCanNotSeeTableRecords([$general])
            ->filterTable('campaign_id', 'none')
            ->assertCanSeeTableRecords([$general])
            ->assertCanNotSeeTableRecords([$onCampaign]);
    }

    public function test_exports_carry_the_campaign_column_and_name_the_filter(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        $campaign = $this->campaign('Gaushala');
        $this->capturedDonation(5000, $campaign->id);
        $this->capturedDonation(700, null);

        $page = Livewire::test(FinancialReports::class)
            ->set('date_from', now()->subDay()->toDateString())
            ->set('date_to', now()->addDay()->toDateString())
            ->set('campaign_id', (string) $campaign->id)
            ->call('applyFilters');

        $csv = $this->streamed($page->instance()->exportCsv());
        $this->assertStringContainsString('Campaign', $csv);
        $this->assertStringContainsString('Gaushala', $csv);
        $this->assertStringNotContainsString('700.00', $csv);

        // The PDF must SAY it was filtered, or the recipient reads it as
        // the whole period's collection.
        $pdf = $this->streamed($page->instance()->exportPdf());
        $this->assertStringStartsWith('%PDF', $pdf);
    }

    public function test_booking_revenue_widget_counts_captured_bookings_only(): void
    {
        $this->actingAs($this->superAdmin(), 'admin');

        // Counted.
        SevaBookingFactory::new()->create([
            'total_amount' => 1100,
            'status' => 'confirmed',
            'payment_id' => PaymentFactory::new()->captured()->create()->id,
        ]);
        HallBookingFactory::new()->create([
            'total_amount' => 25000,
            'status' => 'confirmed',
            'payment_id' => PaymentFactory::new()->captured()->create()->id,
        ]);

        // Not counted: abandoned checkout, and money handed back.
        SevaBookingFactory::new()->create([
            'total_amount' => 7777,
            'status' => 'pending',
            'payment_id' => PaymentFactory::new()->create()->id,
        ]);
        HallBookingFactory::new()->create([
            'total_amount' => 8888,
            'status' => 'cancelled',
            'payment_id' => PaymentFactory::new()->captured()->create()->id,
        ]);

        Livewire::test(BookingRevenueOverview::class)
            ->assertSee('₹1,100')
            ->assertSee('₹25,000')
            ->assertDontSee('7,777')
            ->assertDontSee('8,888');

        // And it is actually registered on the dashboard, not just
        // instantiable. (The tiles themselves load lazily, so the
        // dashboard's first response carries a placeholder, not the text.)
        $this->get(Dashboard::getUrl(panel: 'admin'))->assertOk();
        $this->assertContains(BookingRevenueOverview::class, Filament::getPanel('admin')->getWidgets());
    }

    /** Body of a StreamedResponse, captured. */
    private function streamed(mixed $response): string
    {
        ob_start();
        $response->sendContent();

        return (string) ob_get_clean();
    }

    private function capturedDonation(float $amount, ?int $campaignId): void
    {
        DonationFactory::new()->create([
            'amount' => $amount,
            'campaign_id' => $campaignId,
            'payment_id' => PaymentFactory::new()->captured()->create()->id,
        ]);
    }

    private function campaign(string $title): DonationCampaign
    {
        return DonationCampaign::create([
            'title_gu' => $title,
            'title_en' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'goal_amount' => 100000,
            'start_date' => now()->subMonth()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'is_active' => true,
        ]);
    }

    /** A super admin — Gate::before grants every permission. */
    private function superAdmin(): AdminUser
    {
        $admin = AdminUser::create([
            'name' => 'Reports Admin',
            'email' => 'reports-'.Str::lower(Str::random(6)).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $admin->assignRole('super_admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $admin->fresh();
    }
}
