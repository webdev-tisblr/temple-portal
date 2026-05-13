<?php

namespace App\Providers;

use App\Models\Seva;
use App\Models\SystemSetting;
use App\Observers\SevaObserver;
use Filament\Actions\DeleteAction as PageDeleteAction;
use Filament\Forms\Components\FileUpload;
use Filament\Notifications\Notification;
use Filament\Tables\Actions\DeleteAction as TableDeleteAction;
use Filament\Tables\Actions\DeleteBulkAction;
use Filament\Tables\Columns\ImageColumn;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

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
        Seva::observe(SevaObserver::class);

        // All Filament image uploads land in Cloudflare R2 (the 'r2' disk
        // pins to the public bucket, served via cdn.patadiyahanumanji.com).
        // ImageColumn uses the same disk so list/edit thumbnails resolve to
        // the R2 CDN URL.
        //
        // We solve the "FilePond stuck on Loading / Waiting for size"
        // problem two ways simultaneously, because either one alone has
        // proven flaky on Hostinger:
        //
        // 1. fetchFileInformation(false) — tells Filament's BaseFileUpload
        //    to skip the S3 HEAD calls (exists / size / mimeType) when an
        //    existing file is loaded. Those HEADs against R2 from the
        //    Hostinger PHP-FPM workers either time out or sit on a long
        //    socket. Without the fetch the existing-file path renders
        //    immediately.
        //
        // 2. Custom getUploadedFileUsing — bypasses the entire default
        //    pipeline and returns the file metadata FilePond needs in a
        //    single synchronous call, no disk round-trip whatsoever:
        //      • name  → basename of the stored path
        //      • size  → 1 (any non-zero; FilePond's JS reads size=0
        //                  as "not yet loaded" and stays in
        //                  "Waiting for size" state, so the previous
        //                  fix's size=0 wasn't enough)
        //      • type  → mime guessed from the file extension, no
        //                disk call needed
        //      • url   → CDN URL via $disk->url() (string concat,
        //                no network)
        //    This is the actual fix; fetchFileInformation(false) is the
        //    belt that backs up the suspenders.
        // isImportant: true is the trick that makes the override stick.
        // Filament's ComponentManager runs callbacks in three passes:
        //   1. Normal configureUsing (this would have run here)
        //   2. setUp() — BaseFileUpload::setUp() registers a default
        //      $this->getUploadedFileUsing(...) closure that does the
        //      slow R2 HEAD calls
        //   3. Important configureUsing
        // If we register at the default (false) priority, setUp() in
        // pass 2 immediately overwrites our override. Same regression
        // pattern that bit the delete actions earlier today. Moving
        // into pass 3 ensures OUR closure is the last $this->action
        // assignment and survives.
        FileUpload::configureUsing(function (FileUpload $c) {
            $c->disk('r2')
              ->fetchFileInformation(false)
              ->getUploadedFileUsing(function (FileUpload $component, string $file, string|array|null $storedFileNames): ?array {
                  // Common image MIME types by extension — covers every
                  // upload route in this app (products / sevas / halls
                  // / blog posts / etc all accept image/* only).
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

                  return [
                      'name' => $storedFileNames ?: basename($file),
                      // 1, not 0 — FilePond's JS treats size=0 as
                      // "not yet loaded" and stays in the spinner state.
                      'size' => 1,
                      'type' => $mime,
                      'url' => $component->getDisk()->url($file),
                  ];
              });
        }, isImportant: true);
        ImageColumn::configureUsing(fn (ImageColumn $c) => $c->disk('r2'));

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
            $action->action(function (TableDeleteAction $action, \Illuminate\Database\Eloquent\Model $record) use ($renderFkError) {
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
            $action->action(function (DeleteBulkAction $action, \Illuminate\Database\Eloquent\Collection $records) use ($renderFkError) {
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
                        ->title("Deleted {$deleted} record" . ($deleted === 1 ? '' : 's'))
                        ->success()
                        ->send();
                }
                if ($blocked > 0) {
                    $renderFkError(
                        "{$blocked} record" . ($blocked === 1 ? '' : 's') . ' could not be deleted',
                        'They are referenced by donations, bookings, orders or similar dependent rows. Remove or reassign those first.',
                    );
                }
            });
        }, isImportant: true);

        // Edit-page header action — the trash icon on /admin/.../edit pages.
        PageDeleteAction::configureUsing(function (PageDeleteAction $action) use ($renderFkError) {
            $action->action(function (PageDeleteAction $action, \Illuminate\Database\Eloquent\Model $record) use ($renderFkError) {
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
