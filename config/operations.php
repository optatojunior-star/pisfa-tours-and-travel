<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Health checks
    |--------------------------------------------------------------------------
    |
    | Thresholds for the checks in App\Services\Operations\HealthCheck. These
    | separate "somebody should look at this today" from "the site is down",
    | which is the difference between a useful alert and one people learn to
    | ignore.
    |
    */
    'health' => [
        // A backlog this size means the worker is not keeping up.
        'queue_backlog_warning' => max(1, (int) env('PISFA_QUEUE_BACKLOG_WARNING', 250)),

        // A job waiting longer than this suggests the queue cron is not running.
        'queue_stall_minutes' => max(1, (int) env('PISFA_QUEUE_STALL_MINUTES', 30)),

        // The scheduler runs every minute; more than this and something is wrong.
        'scheduler_stall_minutes' => max(2, (int) env('PISFA_SCHEDULER_STALL_MINUTES', 15)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Backups
    |--------------------------------------------------------------------------
    |
    | The dump is taken through PDO rather than mysqldump, because shared hosts
    | routinely disable exec(). See App\Services\Operations\DatabaseDump.
    |
    | `.env` is never included in a backup: it holds APP_KEY, and an archive
    | containing both the encrypted data and its key gives away everything in a
    | single file. Keep APP_KEY in a secret store.
    |
    | For a genuine off-site copy, set PISFA_BACKUP_DISK to a remote disk (s3,
    | or any configured filesystem). A backup on the same server as the database
    | protects against a bad migration, not against losing the server.
    |
    */
    'backup' => [
        'disk' => env('PISFA_BACKUP_DISK', 'local'),
        'path' => env('PISFA_BACKUP_PATH', 'backups'),

        // The disk holding private media — the evidence that cannot be
        // regenerated: passport scans, signed contracts, inspection photographs.
        'media_disk' => env('PISFA_BACKUP_MEDIA_DISK', 'local'),

        // Retention. The newest backup is never deleted, whatever these say.
        'keep' => max(1, (int) env('PISFA_BACKUP_KEEP', 14)),
        'max_age_days' => max(1, (int) env('PISFA_BACKUP_MAX_AGE_DAYS', 60)),
    ],

];
