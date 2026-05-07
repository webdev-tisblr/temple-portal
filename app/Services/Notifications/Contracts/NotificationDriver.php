<?php

declare(strict_types=1);

namespace App\Services\Notifications\Contracts;

use App\Models\NotificationTemplate;
use App\Services\Notifications\NotificationContext;

interface NotificationDriver
{
    /** Channel identifier this driver handles ('email' | 'whatsapp' | 'push'). */
    public function channel(): string;

    /**
     * Render and dispatch a single template against the supplied context.
     *
     * Returns true on attempted delivery, false when delivery is suppressed
     * (e.g. credentials missing, recipient cannot be resolved). Drivers
     * MUST log failures internally and never throw; the caller treats
     * notifications as best-effort.
     */
    public function send(NotificationTemplate $template, NotificationContext $context): bool;
}
