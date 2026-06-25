<?php

/*
|--------------------------------------------------------------------------
| spatie/laravel-backup configuration
|--------------------------------------------------------------------------
|
| Adapted from the vendor default. Two deliberate departures from the
| stock file (both fix the "local-only backup" bug):
|
|   1. Backups are written to the `r2_backup` disk — a SEPARATE Cloudflare
|      R2 bucket, distinct from temple-public / temple-private, with its
|      OWN credentials. An offsite copy survives a host disk failure or a
|      bad migration wiping the server. The operator MUST add this disk to
|      config/filesystems.php (see CLAUDE.md → MANUAL OPERATOR STEPS).
|
|   2. Failure-class notifications go to BOTH the `mail` and `log` channels
|      so a missing/misconfigured mailer can never silently swallow a
|      "backup failed" alert — it still lands in the application log.
|
| Everything else stays close to the vendor default so future package
| upgrades are easy to diff against.
|
*/

return [

    'backup' => [
        /*
         * The name of this application. You can use this name to monitor
         * the backups.
         */
        'name' => env('APP_NAME', 'patadiya-hanumanji'),

        'source' => [
            'files' => [
                /*
                 * The list of directories and files that will be included in the backup.
                 */
                'include' => [
                    base_path(),
                ],

                /*
                 * These directories and files will be excluded from the backup.
                 *
                 * Directories used by the backup process will automatically be excluded.
                 */
                'exclude' => [
                    base_path('vendor'),
                    base_path('node_modules'),
                ],

                /*
                 * Determines if symlinks should be followed.
                 */
                'follow_links' => false,

                /*
                 * Determines if it should avoid unreadable folders.
                 */
                'ignore_unreadable_directories' => false,

                /*
                 * This path is used to make directories in resulting zip-file relative
                 * Set to `null` to include complete absolute path
                 * Example: base_path()
                 */
                'relative_path' => null,
            ],

            /*
             * The names of the connections to the databases that should be backed up
             * MySQL, PostgreSQL, SQLite and Mongo databases are supported.
             *
             * The content of the database dump may be customized for each connection
             * by adding a 'dump' key to the connection settings in config/database.php.
             */
            'databases' => [
                env('DB_CONNECTION', 'mysql'),
            ],
        ],

        /*
         * The database dump can be compressed to decrease disk space usage.
         */
        'database_dump_compressor' => null,

        /*
         * If specified, the database dumped file name will contain a timestamp (e.g.: 'Y-m-d-H-i-s').
         */
        'database_dump_file_timestamp_format' => null,

        /*
         * The base of the dump filename, either 'database' or 'connection'
         */
        'database_dump_filename_base' => 'database',

        /*
         * The file extension used for the database dump files.
         */
        'database_dump_file_extension' => '',

        'destination' => [
            /*
             * The compression algorithm to be used for creating the zip archive.
             */
            'compression_method' => ZipArchive::CM_DEFAULT,

            /*
             * The compression level corresponding to the used algorithm; an integer between 0 and 9.
             */
            'compression_level' => 9,

            /*
             * The filename prefix used for the backup zip file.
             */
            'filename_prefix' => '',

            /*
             * The disk names on which the backups will be stored.
             *
             * OFFSITE ONLY — `local` is intentionally NOT listed. Backups
             * that live on the same disk as the database they protect are
             * worthless when that disk dies. `r2_backup` is a separate
             * Cloudflare R2 bucket with its own credentials.
             */
            'disks' => [
                'r2_backup',
            ],
        ],

        /*
         * The directory where the temporary files will be stored.
         */
        'temporary_directory' => storage_path('app/backup-temp'),

        /*
         * The password to be used for archive encryption.
         * Set to `null` to disable encryption.
         */
        'password' => env('BACKUP_ARCHIVE_PASSWORD'),

        /*
         * The encryption algorithm to be used for archive encryption.
         */
        'encryption' => 'default',

        /*
         * The number of attempts, in case the backup command encounters an exception
         */
        'tries' => 1,

        /*
         * The number of seconds to wait before attempting a new backup if the previous try failed
         */
        'retry_delay' => 0,
    ],

    /*
     * You can get notified when specific events occur. Out of the box you can use 'mail' and 'slack'.
     *
     * Failure-class notifications fan out to BOTH `mail` and `log` so a
     * misconfigured mailer can never silently swallow a backup-failure
     * alert. Success/healthy notifications are intentionally left off to
     * avoid nightly noise — the absence of a failure alert plus the
     * monitor (below) is the signal that all is well.
     */
    'notifications' => [
        'notifications' => [
            \Spatie\Backup\Notifications\Notifications\BackupHasFailedNotification::class => ['mail', 'log'],
            \Spatie\Backup\Notifications\Notifications\UnhealthyBackupWasFoundNotification::class => ['mail', 'log'],
            \Spatie\Backup\Notifications\Notifications\CleanupHasFailedNotification::class => ['mail', 'log'],
            \Spatie\Backup\Notifications\Notifications\BackupWasSuccessfulNotification::class => [],
            \Spatie\Backup\Notifications\Notifications\HealthyBackupWasFoundNotification::class => [],
            \Spatie\Backup\Notifications\Notifications\CleanupWasSuccessfulNotification::class => [],
        ],

        /*
         * Here you can specify the notifiable to which the notifications should be sent.
         */
        'notifiable' => \Spatie\Backup\Notifications\Notifiable::class,

        'mail' => [
            'to' => env('BACKUP_NOTIFICATION_EMAIL', 'webdeveloper.tisblr@gmail.com'),

            'from' => [
                'address' => env('MAIL_FROM_ADDRESS', 'hello@patadiyahanumanji.com'),
                'name' => env('MAIL_FROM_NAME', 'Patadiya Hanumanji Backups'),
            ],
        ],

        'slack' => [
            'webhook_url' => '',

            'channel' => null,

            'username' => null,

            'icon' => null,
        ],

        'discord' => [
            'webhook_url' => '',

            'username' => '',

            'avatar_url' => '',
        ],
    ],

    /*
     * Here you can specify which backups should be monitored.
     * If a backup does not meet the specified requirements the
     * UnHealthyBackupWasFound event will be fired.
     */
    'monitor_backups' => [
        [
            'name' => env('APP_NAME', 'patadiya-hanumanji'),
            'disks' => ['r2_backup'],
            'health_checks' => [
                \Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumAgeInDays::class => 1,
                \Spatie\Backup\Tasks\Monitor\HealthChecks\MaximumStorageInMegabytes::class => 5000,
            ],
        ],
    ],

    'cleanup' => [
        /*
         * The strategy that will be used to cleanup old backups.
         */
        'strategy' => \Spatie\Backup\Tasks\Cleanup\Strategies\DefaultStrategy::class,

        'default_strategy' => [
            /*
             * The number of days for which backups must be kept.
             */
            'keep_all_backups_for_days' => 7,

            /*
             * After the "keep_all_backups_for_days" period is over, the most recent backup
             * of that day will be kept.
             */
            'keep_daily_backups_for_days' => 16,

            /*
             * After the "keep_daily_backups_for_days" period is over, the most recent backup
             * of that week will be kept.
             */
            'keep_weekly_backups_for_weeks' => 8,

            /*
             * After the "keep_weekly_backups_for_weeks" period is over, the most recent backup
             * of that month will be kept.
             */
            'keep_monthly_backups_for_months' => 4,

            /*
             * After the "keep_monthly_backups_for_months" period is over, the most recent backup
             * of that year will be kept.
             */
            'keep_yearly_backups_for_years' => 2,

            /*
             * After cleaning up the backups remove the oldest backup until
             * this amount of megabytes has been reached.
             */
            'delete_oldest_backups_when_using_more_megabytes_than' => 5000,
        ],

        /*
         * The number of attempts, in case the cleanup command encounters an exception
         */
        'tries' => 1,

        /*
         * The number of seconds to wait before attempting a new cleanup if the previous try failed
         */
        'retry_delay' => 0,
    ],

];
