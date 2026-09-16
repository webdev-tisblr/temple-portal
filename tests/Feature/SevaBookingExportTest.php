<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\SevaBookingResource\Pages\ListSevaBookings;
use App\Models\AdminUser;
use Database\Factories\DevoteeFactory;
use Database\Factories\SevaBookingFactory;
use Database\Factories\SevaFactory;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

/**
 * The seva bookings export (2026-09-16) downloads the list AS SHOWN — the
 * same filters, search and sort the admin has applied — rather than
 * re-asking for a date range. These tests pin that contract: what the
 * table hides, the file must not contain.
 */
class SevaBookingExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $admin = AdminUser::create([
            'name' => 'Export Admin',
            'email' => 'export-'.Str::lower(Str::random(6)).'@example.test',
            'password' => 'password',
            'is_active' => true,
        ]);
        $admin->assignRole('super_admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs($admin->fresh(), 'admin');
    }

    private function downloadedCsv(Testable $component): string
    {
        $component->assertFileDownloaded();

        return base64_decode((string) data_get($component->effects, 'download.content'));
    }

    public function test_csv_export_respects_the_default_filters(): void
    {
        $shown = SevaBookingFactory::new()->create([
            'status' => 'confirmed',
            'booking_date' => now()->addDays(2)->toDateString(),
            'devotee_id' => DevoteeFactory::new()->create(['name' => 'Shown Devotee'])->id,
        ]);
        // Hidden by "Paid bookings only" (on by default).
        SevaBookingFactory::new()->create([
            'status' => 'cancelled',
            'booking_date' => now()->addDays(2)->toDateString(),
            'devotee_id' => DevoteeFactory::new()->create(['name' => 'Abandoned Devotee'])->id,
        ]);
        // Hidden by Timeframe = Upcoming (on by default).
        SevaBookingFactory::new()->create([
            'status' => 'confirmed',
            'booking_date' => now()->subDays(10)->toDateString(),
            'devotee_id' => DevoteeFactory::new()->create(['name' => 'Past Devotee'])->id,
        ]);

        $component = Livewire::test(ListSevaBookings::class)
            ->callAction('export', ['format' => 'csv']);

        $csv = $this->downloadedCsv($component);

        $this->assertStringContainsString('Shown Devotee', $csv);
        $this->assertStringContainsString($shown->devotee->phone, $csv);
        $this->assertStringNotContainsString('Abandoned Devotee', $csv);
        $this->assertStringNotContainsString('Past Devotee', $csv);
        // The trailer names the filters that shaped the file.
        $this->assertStringContainsString('Paid bookings only', $csv);
        $this->assertStringContainsString('Upcoming (today onwards)', $csv);
    }

    public function test_csv_export_follows_a_changed_filter_and_the_search_box(): void
    {
        $flag = SevaFactory::new()->create(['name_en' => 'Flag Seva']);
        $shringar = SevaFactory::new()->create(['name_en' => 'Shringar Seva']);

        SevaBookingFactory::new()->create([
            'seva_id' => $flag->id,
            'status' => 'confirmed',
            'devotee_id' => DevoteeFactory::new()->create(['name' => 'Flag Devotee'])->id,
        ]);
        SevaBookingFactory::new()->create([
            'seva_id' => $shringar->id,
            'status' => 'confirmed',
            'devotee_id' => DevoteeFactory::new()->create(['name' => 'Shringar Devotee'])->id,
        ]);
        // Cancelled, but the operator unticked "paid only" — must appear.
        SevaBookingFactory::new()->create([
            'seva_id' => $flag->id,
            'status' => 'cancelled',
            'devotee_id' => DevoteeFactory::new()->create(['name' => 'Flag Cancelled'])->id,
        ]);

        $component = Livewire::test(ListSevaBookings::class)
            ->filterTable('paid_only', false)
            ->filterTable('seva_id', $flag->id)
            ->callAction('export', ['format' => 'csv']);

        $csv = $this->downloadedCsv($component);

        $this->assertStringContainsString('Flag Devotee', $csv);
        $this->assertStringContainsString('Flag Cancelled', $csv);
        $this->assertStringNotContainsString('Shringar Devotee', $csv);

        // Search narrows it further, and is recorded in the trailer.
        $component = Livewire::test(ListSevaBookings::class)
            ->filterTable('paid_only', false)
            ->searchTable('Flag Cancelled')
            ->callAction('export', ['format' => 'csv']);

        $csv = $this->downloadedCsv($component);

        $this->assertStringContainsString('Flag Cancelled', $csv);
        $this->assertStringNotContainsString('Flag Devotee', $csv);
        $this->assertStringNotContainsString('Shringar Devotee', $csv);
        $this->assertStringContainsString('Search: Flag Cancelled', $csv);
    }

    public function test_pdf_export_downloads_a_pdf(): void
    {
        SevaBookingFactory::new()->create([
            'status' => 'confirmed',
            'seva_id' => SevaFactory::new()->create(['name_en' => 'Sundarkand', 'name_gu' => 'સુંદરકાંડ'])->id,
        ]);

        $component = Livewire::test(ListSevaBookings::class)
            ->callAction('export', ['format' => 'pdf']);

        $component->assertFileDownloaded(null, null, 'application/pdf');

        $bytes = base64_decode((string) data_get($component->effects, 'download.content'));
        $this->assertStringStartsWith('%PDF', $bytes);
        $this->assertStringStartsWith('seva-bookings-upcoming-', (string) data_get($component->effects, 'download.name'));
    }
}
