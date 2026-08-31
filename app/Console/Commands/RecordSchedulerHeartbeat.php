<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

/**
 * Writes the timestamp the scheduler health check reads.
 *
 * The scheduler failing is a silent failure by nature: nothing errors, reminders
 * simply stop going out, and the first anybody hears of it is a customer who
 * did not get their pickup reminder. A heartbeat turns that into something a
 * health check can see.
 *
 * Kept as its own command rather than folded into an existing scheduled task,
 * so the heartbeat proves the *scheduler* is running rather than proving that
 * one particular job still works.
 */
class RecordSchedulerHeartbeat extends Command
{
    protected $signature = 'pisfa:heartbeat';

    protected $description = 'Record that the scheduler ran, for the health check to read';

    public function handle(): int
    {
        // Kept well beyond any sane alert threshold, so a stale heartbeat reads
        // as "the scheduler stopped an hour ago" rather than as no heartbeat at
        // all, which is ambiguous with a fresh deployment.
        Cache::put('operations:scheduler-heartbeat', now()->toIso8601String(), now()->addDay());

        return self::SUCCESS;
    }
}
