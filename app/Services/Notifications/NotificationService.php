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

        $ctx = new NotificationContext($context);
        $delivered = 0;

        // Wrap every per-template send in its own try/catch so one
        // dead channel (SMTP timeout, WhatsApp 5xx, etc.) cannot bring
        // down the whole trigger. Earlier an uncaught exception bubbled
        // up to the caller, killed the request, and any subsequent
        // template — eg WhatsApp after a failing email — never ran.
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
                // Continue — next template still gets a chance.
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
