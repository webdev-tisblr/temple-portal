<?php

namespace App\Providers;

use App\Models\Seva;
use App\Models\SystemSetting;
use App\Observers\SevaObserver;
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

        $this->configureMailFromDatabase();
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
                'mail.from.address' => SystemSetting::getValue('mail_from_address', (string) config('mail.from.address', '')),
                'mail.from.name' => SystemSetting::getValue('mail_from_name', (string) config('mail.from.name', '')),
            ]);
        } catch (\Exception $e) {
            // Silently fail during migrations or when DB is unavailable
        }
    }
}
