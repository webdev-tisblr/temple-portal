<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Queue-backed notification dispatch
    |--------------------------------------------------------------------------
    |
    | When true, NotificationService::dispatch() enqueues a
    | SendQueuedNotification job (auth.otp on the high-priority `otp`
    | queue, everything else on `default`) instead of sending inline via
    | app()->terminating(). Requires an always-on queue worker — enable
    | only where Supervisor runs one (the VPS). Dispatches carrying
    | _attachments, or whose context snapshot would truncate, always use
    | the inline path regardless of this flag.
    |
    | Default false: shared-hosting-era behaviour, also what the test
    | suite and local dev run with.
    |
    */

    'via_queue' => env('NOTIFICATIONS_VIA_QUEUE', false),

];
