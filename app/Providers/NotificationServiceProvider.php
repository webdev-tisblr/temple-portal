<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\Notifications\Contracts\NotificationDriver;
use App\Services\Notifications\Drivers\EmailNotificationDriver;
use App\Services\Notifications\Drivers\PushNotificationDriver;
use App\Services\Notifications\Drivers\SmsNotificationDriver;
use App\Services\Notifications\Drivers\WhatsAppNotificationDriver;
use App\Services\Notifications\NotificationService;
use App\Services\Notifications\RecipientResolver;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the centralised notification dispatcher together. New channels
 * register a driver here; everything else (the events, the admin UI,
 * the WhatsApp template cache) plugs in independently and stays
 * decoupled from the dispatcher itself.
 */
class NotificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Each driver is a singleton — they're stateless aside from
        // their constructor-injected collaborators.
        $this->app->singleton(EmailNotificationDriver::class);
        $this->app->singleton(WhatsAppNotificationDriver::class);
        $this->app->singleton(SmsNotificationDriver::class);
        $this->app->singleton(PushNotificationDriver::class);

        $this->app->tag([
            EmailNotificationDriver::class,
            WhatsAppNotificationDriver::class,
            SmsNotificationDriver::class,
            PushNotificationDriver::class,
        ], NotificationDriver::class);

        $this->app->singleton(NotificationService::class, function (Application $app) {
            return new NotificationService(
                $app->tagged(NotificationDriver::class),
                $app->make(RecipientResolver::class),
            );
        });
    }
}
