<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use App\Models\NotificationTemplate;
use App\Services\Notifications\Contracts\NotificationDriver;
use Illuminate\Support\Facades\Log;

/**
 * Central dispatcher. Every email / WhatsApp / push that the platform
 * fires goes through here:
 *
 *   app(NotificationService::class)->dispatch('donation.confirmed', [
 *       'donation' => $donation,
 *       'devotee' => $donation->devotee,
 *   ]);
 *
 * The dispatcher loads every enabled NotificationTemplate row matching
 * `$key`, regardless of channel — so a single trigger fans out to email,
 * WhatsApp and push automatically when the corresponding rows exist.
 *
 * Drivers are passed in via the container; new channels are registered
 * by adding them to `NotificationServiceProvider::register`.
 */
final class NotificationService
{
    /** @var array<string, NotificationDriver> keyed by channel string */
    private array $drivers = [];

    /** @param iterable<NotificationDriver> $drivers */
    public function __construct(iterable $drivers)
    {
        foreach ($drivers as $driver) {
            $this->drivers[$driver->channel()] = $driver;
        }
    }

    /**
     * Dispatch every enabled template for `$key`.
     *
     * Returns the number of templates that successfully entered their
     * channel's transport — useful for tests and for `Send Test`
     * actions in the admin UI. Failures are logged, never thrown.
     */
    /**
     * Fire every enabled template for $key.
     *
     * Inside an HTTP request the actual SMTP / WhatsApp work is
     * deferred to `app()->terminating()`, which fires AFTER the
     * response is flushed to the user (via PHP-FPM's
     * fastcgi_finish_request). The caller's request returns in
     * ~1 second; the recipient sees the email / WhatsApp arrive
     * 5-10 seconds later as the same PHP-FPM worker finishes its
     * lifecycle in the background.
     *
     * For CLI / console / queue contexts there's no response to
     * flush, so we run inline — the worker is the only thing
     * waiting anyway.
     *
     * Returns the number of templates delivered when running inline;
     * 0 when deferred (the count won't be known until after this
     * method returns, by design).
     */
    public function dispatch(string $key, array $context): int
    {
        $templates = NotificationTemplate::query()
            ->where('key', $key)
            ->where('is_enabled', true)
            ->get();

        if ($templates->isEmpty()) {
            Log::info('Notification: no enabled templates for key', ['key' => $key]);
            return 0;
        }

        // CLI / console — no response to flush, run inline.
        if (app()->runningInConsole()) {
            return $this->dispatchInline($key, $templates, $context);
        }

        // HTTP request — flush response first, then send notifications.
        app()->terminating(function () use ($key, $templates, $context) {
            try {
                $this->dispatchInline($key, $templates, $context);
            } catch (\Throwable $e) {
                Log::error('Notification: deferred dispatch threw', [
                    'key' => $key,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        Log::info('Notification: dispatch deferred to after-response', [
            'key' => $key,
            'template_count' => $templates->count(),
        ]);
        return 0;
    }

    /**
     * Force-inline dispatch. Used by:
     *   • CLI commands and queued jobs (no response to defer to)
     *   • The "Send test" action in the Filament admin, which
     *     needs synchronous feedback for the success/failure
     *     notification badge.
     */
    public function dispatchNow(string $key, array $context): int
    {
        $templates = NotificationTemplate::query()
            ->where('key', $key)
            ->where('is_enabled', true)
            ->get();

        if ($templates->isEmpty()) {
            Log::info('Notification: no enabled templates for key', ['key' => $key]);
            return 0;
        }

        return $this->dispatchInline($key, $templates, $context);
    }

    /**
     * Render + send every $templates row against $context. Isolated
     * per-template via try/catch so one dead channel (SMTP timeout,
     * WhatsApp 5xx, etc.) doesn't suppress the others.
     *
     * @param \Illuminate\Support\Collection<int, NotificationTemplate> $templates
     */
    private function dispatchInline(string $key, $templates, array $context): int
    {
        $ctx = new NotificationContext($context);
        $delivered = 0;

        foreach ($templates as $template) {
            try {
                $ok = $this->sendTemplate($template, $ctx);
                $delivered += $ok ? 1 : 0;
            } catch (\Throwable $e) {
                Log::error('Notification: template send threw unexpectedly', [
                    'template_id' => $template->id,
                    'template_key' => $template->key,
                    'channel' => $template->channel,
                    'error' => $e->getMessage(),
                ]);
            }
        }
        return $delivered;
    }

    /**
     * Send a single specific template. Used by the admin "Send test"
     * action and by callers that need to bypass the enabled flag.
     */
    public function sendTemplate(NotificationTemplate $template, NotificationContext|array $context): bool
    {
        $ctx = $context instanceof NotificationContext ? $context : new NotificationContext($context);
        $driver = $this->drivers[$template->channel] ?? null;
        if ($driver === null) {
            Log::warning('Notification: no driver registered for channel', [
                'channel' => $template->channel,
                'template_id' => $template->id,
            ]);
            return false;
        }
        return $driver->send($template, $ctx);
    }
}
