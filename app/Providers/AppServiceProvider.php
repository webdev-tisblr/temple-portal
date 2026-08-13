<?php

namespace App\Providers;

use App\Models\HallBooking;
use App\Models\Seva;
use App\Models\SevaBooking;
use App\Models\SystemSetting;
use App\Observers\HallBookingObserver;
use App\Observers\SevaBookingObserver;
use App\Observers\SevaObserver;
use App\Services\UploadedImageCompressor;
use Filament\Actions\DeleteAction as PageDeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Section as FormSection;
use Filament\Infolists\Components\Section as InfolistSection;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteAction as TableDeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\ImageColumn;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Rate limit for the MSG91 delivery-report webhook.
        //
        // Sized for the real traffic shape rather than for safety theatre:
        // delivery reports arrive in a burst right after a batch send, so
        // the API group's 60/min would 429 legitimate reports — and MSG91
        // responds to a non-2xx by retrying, which makes the burst worse.
        // 300/min per IP still caps an abusive caller (the endpoint only
        // ever writes an audit row and a delivery status), while leaving
        // ample headroom for the trust's largest send.
        RateLimiter::for('msg91-webhook', fn (Request $request) => Limit::perMinute(300)->by($request->ip()));

        // Live donor display board feed. Keyed by the BOARD TOKEN, not the IP,
        // and that distinction is the whole point: the hall screen sits on the
        // temple's wifi behind the same public IP as every devotee using the
        // app, so an IP-keyed bucket would be drained by the crowd at exactly
        // the busiest moment of the year and the screen would freeze on a 429.
        // Token-keying isolates the board from the hall's traffic while still
        // capping anyone who copies the token off the screen's address bar.
        RateLimiter::for('display-board', fn (Request $request) => Limit::perMinute(120)->by(
            (string) ($request->header('X-Board-Token') ?: $request->query('token', $request->ip()))
        ));

        // Every admin section is collapsible, everywhere (2026-08-13).
        //
        // Set globally rather than by editing 137 Section::make() calls
        // across the resources: a default that has to be repeated by hand is
        // a default that will be missed on the next resource somebody adds,
        // which is exactly how it came to be present on some pages and
        // absent on others.
        //
        // configureUsing runs inside make(), BEFORE the chained calls in a
        // resource, so anything explicit still wins — a section that already
        // says ->collapsed() keeps opening collapsed, and one that says
        // ->collapsible(false) stays fixed.
        //
        // Only sections with a heading get a chevron. The three headingless
        // Section::make() calls are plain layout wrappers with no header bar
        // to put a toggle in, and collapsing one would hide fields behind an
        // unlabelled arrow.
        //
        // Collapsed state PERSISTS per section, per page, per browser. That
        // needs a stable DOM id: Filament's persistCollapsed keys its Alpine
        // store on `section-${$el.id}-isCollapsed`, and our sections set no
        // id, so every one of them would key on the SAME empty string —
        // collapsing one section would collapse every section on every page.
        //
        // The id is derived at RENDER time (a closure, not a string) because
        // the Livewire component is not known when the section is built.
        // Heading alone would make "Status" on Products and "Status" on
        // Sevas share one state; scoping by the page class keeps them apart.
        $collapsible = function ($section): void {
            if (blank($section->getHeading())) {
                return;
            }

            $section
                ->collapsible()
                ->id(function ($component): ?string {
                    $heading = strip_tags((string) $component->getHeading());

                    if ($heading === '') {
                        return null;
                    }

                    // Outside a Livewire request there is no page to scope
                    // to — fall back rather than throw, since an id is a
                    // convenience here and never correctness.
                    $page = rescue(
                        fn (): string => class_basename($component->getLivewire()),
                        'admin',
                        report: false,
                    );

                    return 'sec-'.Str::slug($page.'-'.$heading);
                })
                ->persistCollapsed();
        };

        FormSection::configureUsing($collapsible);
        InfolistSection::configureUsing($collapsible);

        Seva::observe(SevaObserver::class);
        // Materialises / cancels seva reminder schedule rows as bookings
        // change state, on every confirm path. See SevaReminderScheduler.
        SevaBooking::observe(SevaBookingObserver::class);
        // Same contract as the seva observer: it runs inside the payment
        // capture transaction and must never throw.
        HallBooking::observe(HallBookingObserver::class);

        // All Filament image uploads land in Cloudflare R2 (the 'r2' disk
        // pins to the public bucket, served via cdn.patadiyahanumanji.com).
        // ImageColumn uses the same disk so list/edit thumbnails resolve to
        // the R2 CDN URL.
        //
        // Two overrides survive the Filament setUp() pass via
        // isImportant: true (default priority runs BEFORE setUp() which
        // would overwrite our changes):
        //
        // 1. fetchFileInformation(false) — skip the S3 HEAD calls
        //    (exists / size / mimeType) on every existing-file load. Those
        //    HEADs against R2 from Hostinger's PHP-FPM workers either
        //    time out or sit on a long socket, leaving the FileUpload
        //    panel stuck on "Loading / Waiting for size."
        //
        // 2. Custom getUploadedFileUsing — return file metadata
        //    synchronously without any disk round-trip:
        //      • name → basename
        //      • size → 1 (NON-zero; FilePond's JS treats size=0 as
        //                  "not loaded yet" and stays in the spinner)
        //      • type → mime guessed from extension, no disk call
        //      • url  → CDN URL via $disk->url() (pure string concat)
        //
        // CORS on the cdn.patadiyahanumanji.com bucket must allow
        // https://patadiyahanumanji.com as an origin or FilePond's
        // XHR fetch of the image (for canvas preview) will hang.
        // Configured in Cloudflare R2 dashboard → bucket Settings →
        // CORS Policy.
        FileUpload::configureUsing(function (FileUpload $c) {
            $c->disk('r2')
                ->fetchFileInformation(false)
                ->getUploadedFileUsing(function (FileUpload $component, string $file, string|array|null $storedFileNames): ?array {
                    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
                    $mime = match ($ext) {
                        'jpg', 'jpeg' => 'image/jpeg',
                        'png' => 'image/png',
                        'webp' => 'image/webp',
                        'gif' => 'image/gif',
                        'svg' => 'image/svg+xml',
                        'pdf' => 'application/pdf',
                        default => 'application/octet-stream',
                    };

                    // In multiple() mode Filament hands the whole map of stored
                    // names, keyed by path — passing the array straight through
                    // would show "Array" as every file's name in FilePond.
                    $name = is_array($storedFileNames)
                        ? ($storedFileNames[$file] ?? basename($file))
                        : ($storedFileNames ?: basename($file));

                    return [
                        'name' => $name,
                        'size' => 1,
                        'type' => $mime,
                        'url' => $component->getDisk()->url($file),
                    ];
                })
              // Shrink photos before they reach R2 — see UploadedImageCompressor
              // for the why and the measured numbers. Applies to every upload in
              // the admin, not just the gallery. Anything the compressor declines
              // (PDFs, SVG, animated GIF, already-small images, an image it fails
              // to decode) falls through to Filament's stock store, untouched.
                ->saveUploadedFileUsing(function (FileUpload $component, TemporaryUploadedFile $file): ?string {
                    try {
                        if (! $file->exists()) {
                            return null;
                        }
                    } catch (\Throwable) {
                        return null;
                    }

                    $store = fn (): ?string => $file->{$component->getVisibility() === 'public' ? 'storePubliclyAs' : 'storeAs'}(
                        $component->getDirectory(),
                        $component->getUploadedFileNameForStorage($file),
                        $component->getDiskName(),
                    );

                    $local = $file->getRealPath();

                    if ($local === false || $local === '') {
                        return $store();
                    }

                    $compressed = app(UploadedImageCompressor::class)->compress(
                        $local,
                        $file->getClientOriginalExtension(),
                    );

                    if ($compressed === null) {
                        return $store();
                    }

                    // Keep Filament's ULID filename but carry the extension the
                    // encoder actually produced (a .jpeg upload comes back .jpg).
                    $name = preg_replace('/\.[^.]+$/', '', $component->getUploadedFileNameForStorage($file));
                    $path = trim($component->getDirectory().'/'.$name.'.'.$compressed['extension'], '/');

                    $component->getDisk()->put($path, $compressed['bytes'], [
                        'visibility' => $component->getVisibility(),
                        'ContentType' => $compressed['mime'],
                    ]);

                    return $path;
                });
        }, isImportant: true);
        ImageColumn::configureUsing(fn (ImageColumn $c) => $c->disk('r2'));

        // Clean every RichEditor's HTML on save (admin-wide, including
        // page-builder blocks): pasted content arrives padded with &nbsp;
        // runs and empty paragraphs which then leak into cards/previews.
        // clean_rich_html() only normalises whitespace — never the author's
        // words or markup. Existing rows were swept once by the
        // 2026_07_11_100004 data migration.
        RichEditor::configureUsing(function (RichEditor $editor) {
            $editor->dehydrateStateUsing(
                fn ($state) => is_string($state) ? clean_rich_html($state) : $state
            );
        }, isImportant: true);

        $this->configureMailFromDatabase();
        $this->configureFilamentDeleteActions();
    }

    /**
     * Wrap every Filament delete action with friendly FK-violation
     * handling. After dropping SoftDeletes (2026_05_13) admin clicks
     * fire real DELETE statements; MySQL refuses to remove a parent
     * row that still has dependent children (eg deleting a devotee who
     * has donations) with SQLSTATE 23000.
     *
     * Default Filament behaviour bubbled the QueryException to the
     * Laravel exception handler → 500 page. We catch it instead, show
     * a clear Filament notification telling the admin why the delete
     * failed, and leave the page intact so they can decide what to do.
     *
     * Critical Filament-3 detail: configureUsing() callbacks run
     * BEFORE the component's setUp() by default — see
     * Filament\Support\Components\ComponentManager::configure(). That
     * means a plain configureUsing() that calls $action->action(...)
     * gets clobbered by the DeleteBulkAction::setUp() that runs
     * immediately after and re-sets its own default $this->action(...).
     *
     * The fix is to pass isImportant: true, which moves the callback
     * into the SECOND pass that runs AFTER setUp() — our ->action()
     * override is then the last one wired and actually takes effect.
     *
     * Three delete action surfaces in this app: per-row table actions
     * (Tables\Actions\DeleteAction), bulk table actions
     * (Tables\Actions\DeleteBulkAction), and page header actions
     * (Filament\Actions\DeleteAction on Edit pages). All three get
     * the same wrapper.
     */
    private function configureFilamentDeleteActions(): void
    {
        $renderFkError = function (string $title, ?string $detail = null) {
            Notification::make()
                ->title($title)
                ->body($detail ?: 'This record is linked to other data — donations, bookings, orders or similar. Remove or reassign those dependent rows first.')
                ->danger()
                ->persistent()
                ->send();
        };

        // Per-row table action — "Delete" link on each row.
        TableDeleteAction::configureUsing(function (TableDeleteAction $action) use ($renderFkError) {
            $action->action(function (TableDeleteAction $action, Model $record) use ($renderFkError) {
                try {
                    $record->delete();
                    Notification::make()->title('Deleted')->success()->send();
                } catch (QueryException $e) {
                    if ($e->getCode() === '23000') {
                        $renderFkError('Cannot delete — referenced by other records');
                        $action->cancel();

                        return;
                    }
                    throw $e;
                }
            });
        }, isImportant: true);

        // Bulk table action — selected-rows checkbox → Delete selected.
        // Walk one record at a time so a single offender doesn't abort
        // the whole batch; collect failures and surface them at the end.
        DeleteBulkAction::configureUsing(function (DeleteBulkAction $action) use ($renderFkError) {
            $action->action(function (DeleteBulkAction $action, Collection $records) use ($renderFkError) {
                $deleted = 0;
                $blocked = 0;
                foreach ($records as $record) {
                    try {
                        $record->delete();
                        $deleted++;
                    } catch (QueryException $e) {
                        if ($e->getCode() === '23000') {
                            $blocked++;

                            continue;
                        }
                        throw $e;
                    }
                }
                if ($deleted > 0) {
                    Notification::make()
                        ->title("Deleted {$deleted} record".($deleted === 1 ? '' : 's'))
                        ->success()
                        ->send();
                }
                if ($blocked > 0) {
                    $renderFkError(
                        "{$blocked} record".($blocked === 1 ? '' : 's').' could not be deleted',
                        'They are referenced by donations, bookings, orders or similar dependent rows. Remove or reassign those first.',
                    );
                }
            });
        }, isImportant: true);

        // Edit-page header action — the trash icon on /admin/.../edit pages.
        PageDeleteAction::configureUsing(function (PageDeleteAction $action) use ($renderFkError) {
            $action->action(function (PageDeleteAction $action, Model $record) use ($renderFkError) {
                try {
                    $record->delete();
                    Notification::make()->title('Deleted')->success()->send();

                    return $action->getLivewire()->redirect(
                        $action->getLivewire()::getResource()::getUrl('index')
                    );
                } catch (QueryException $e) {
                    if ($e->getCode() === '23000') {
                        $renderFkError('Cannot delete — referenced by other records');
                        $action->cancel();

                        return;
                    }
                    throw $e;
                }
            });
        }, isImportant: true);
    }

    /**
     * Override Laravel mail config with DB settings (if configured).
     */
    private function configureMailFromDatabase(): void
    {
        try {
            if (! Schema::hasTable('temple_system_settings')) {
                return;
            }

            $driver = SystemSetting::getValue('mail_driver');
            if (empty($driver)) {
                return;
            }

            config([
                'mail.default' => $driver,
                'mail.mailers.smtp.host' => SystemSetting::getValue('mail_host', (string) config('mail.mailers.smtp.host', '')),
                'mail.mailers.smtp.port' => (int) SystemSetting::getValue('mail_port', (string) config('mail.mailers.smtp.port', '587')),
                'mail.mailers.smtp.encryption' => SystemSetting::getValue('mail_encryption', (string) config('mail.mailers.smtp.encryption', 'tls')) ?: null,
                'mail.mailers.smtp.username' => SystemSetting::getValue('mail_username', (string) config('mail.mailers.smtp.username', '')),
                'mail.mailers.smtp.password' => SystemSetting::getValue('mail_password', (string) config('mail.mailers.smtp.password', '')),
                'mail.mailers.smtp.timeout' => (int) (config('mail.mailers.smtp.timeout') ?: 10),
                'mail.from.address' => SystemSetting::getValue('mail_from_address', (string) config('mail.from.address', '')),
                'mail.from.name' => SystemSetting::getValue('mail_from_name', (string) config('mail.from.name', '')),
            ]);
        } catch (\Exception $e) {
            // Silently fail during migrations or when DB is unavailable
        }
    }
}
