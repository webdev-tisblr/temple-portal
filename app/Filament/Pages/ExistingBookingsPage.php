<?php

declare(strict_types=1);

namespace App\Filament\Pages;

use App\Services\ExistingBookingImporter;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Load dates that were booked OFF the platform — on paper or over the phone.
 *
 * The loop this page exists for: DOWNLOAD the sheet (every date currently
 * blocked or booked, member columns blank), fill in who booked and what they
 * paid, UPLOAD the same file back. Every row is idempotent, so re-uploading a
 * fuller version of the same sheet adds the details without duplicating
 * anything — which is why the download hands back real rows rather than an
 * empty template.
 *
 * Preview first is the default, deliberately: this writes real bookings and
 * closes real dates, and a typo in a date column is invisible until a devotee
 * cannot book. The import runs only after the operator has seen the outcome.
 *
 * The mechanics live in ExistingBookingImporter, shared with
 * `php artisan temple:import-bookings`.
 */
class ExistingBookingsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-calendar-days';

    protected static ?string $navigationGroup = 'Bookings';

    protected static ?string $navigationLabel = 'Existing bookings (CSV)';

    protected static ?string $title = 'Existing bookings — block dates booked off the platform';

    protected static ?int $navigationSort = 90;

    protected static string $view = 'filament.pages.existing-bookings';

    public ?array $data = [];

    /** Result of the last preview or import, rendered under the form. */
    public ?array $result = null;

    public function mount(): void
    {
        $this->form->fill();
    }

    public function form(Form $form): Form
    {
        return $form
            ->schema([
                Forms\Components\Section::make('1 — Download the current sheet')
                    ->description('Every date already blocked or booked, with the member columns blank. Fill in who booked and what they paid, then upload it back below.')
                    ->schema([
                        Forms\Components\Placeholder::make('download_help')
                            ->hiddenLabel()
                            ->content(new HtmlString(
                                '<p class="text-sm">Columns: <code>kind, target_id, target_name, date, end_date, slot, '
                                .'member_name, member_phone, amount, notes</code>.</p>'
                                .'<ul class="text-sm list-disc ps-5 mt-2 space-y-1">'
                                .'<li><strong>kind = blackout</strong> — closes the whole day. Use when the date is taken '
                                .'but you do not yet know who booked it: no money is recorded.</li>'
                                .'<li><strong>kind = seva</strong> — a real booking. The only one that holds a single '
                                .'<em>slot</em> (so Dhwaja 11:00 can be taken while 08:00 stays open), and the only one '
                                .'that appears in the books.</li>'
                                .'<li><strong>kind = hall</strong> — a real hall booking; set <code>end_date</code> for '
                                .'a multi-day let.</li>'
                                .'</ul>'
                                .'<p class="text-sm mt-2">Leave <code>amount</code> blank to use the list price. '
                                .'Re-uploading the same sheet is safe — nothing is duplicated.</p>'
                            )),
                    ]),

                Forms\Components\Section::make('2 — Upload the filled sheet')
                    ->schema([
                        Forms\Components\FileUpload::make('csv')
                            ->label('CSV file')
                            ->acceptedFileTypes(['text/csv', 'text/plain', 'application/csv', 'application/vnd.ms-excel'])
                            ->disk('local')
                            ->directory('booking-imports')
                            ->required()
                            ->helperText('Exported from Excel or Google Sheets as CSV.'),
                    ]),
            ])
            ->statePath('data');
    }

    /** Hand back the current state as a sheet to fill in. */
    public function download(ExistingBookingImporter $importer): StreamedResponse
    {
        $csv = $importer->exportCurrent();

        return response()->streamDownload(
            fn () => print ($csv),
            'existing-bookings-'.now()->format('Y-m-d').'.csv',
            ['Content-Type' => 'text/csv'],
        );
    }

    public function preview(): void
    {
        $this->run(dryRun: true);
    }

    public function import(): void
    {
        $this->run(dryRun: false);
    }

    /**
     * Parse the upload and hand it to the importer.
     *
     * A row that fails is reported and the rest still run — one bad date must
     * not abandon the other forty.
     */
    private function run(bool $dryRun): void
    {
        $state = $this->form->getState();
        $stored = $state['csv'] ?? null;

        // FileUpload gives either a single path or a one-item array.
        $path = is_array($stored) ? (string) reset($stored) : (string) $stored;

        if ($path === '' || ! Storage::disk('local')->exists($path)) {
            Notification::make()->title('Upload a CSV first')->danger()->send();

            return;
        }

        $importer = app(ExistingBookingImporter::class);
        $rows = $importer->readCsv(Storage::disk('local')->path($path));

        if ($rows === []) {
            Notification::make()->title('That file has no data rows')->danger()->send();

            return;
        }

        $outcome = $importer->import($rows, $dryRun);
        $this->result = $outcome + ['dry_run' => $dryRun];

        $stats = $outcome['stats'];
        $summary = sprintf(
            '%d date(s) blocked · %d booking(s) · %d already present · %d failed',
            $stats['blocked'], $stats['booked'], $stats['already'], $stats['failed'],
        );

        Notification::make()
            ->title($dryRun ? 'Preview only — nothing saved' : 'Import complete')
            ->body($summary)
            ->status($stats['failed'] > 0 ? 'warning' : 'success')
            ->send();
    }
}
