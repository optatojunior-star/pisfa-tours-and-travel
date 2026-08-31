<?php

namespace App\Services\Operations;

use Illuminate\Database\Migrations\Migrator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * What the deployment can actually do right now.
 *
 * Every check proves something by doing it rather than by reading
 * configuration. A check that confirms a disk is *configured* tells you nothing
 * about whether the process can write to it, and on shared hosting the second
 * of those is the one that fails.
 *
 * No check may throw. One broken subsystem must not take the health report down
 * with it — the report is what somebody reads to find out which subsystem is
 * broken.
 */
class HealthCheck
{
    public function __construct(private readonly Migrator $migrator) {}

    /**
     * Runs everything.
     *
     * @return list<HealthCheckResult>
     */
    public function run(): array
    {
        return [
            $this->database(),
            $this->migrations(),
            $this->privateStorage(),
            $this->publicStorage(),
            $this->cache(),
            $this->queue(),
            $this->failedJobs(),
            $this->applicationKey(),
            $this->schedule(),
        ];
    }

    /** The one-line verdict for the whole deployment. */
    public function overall(): HealthStatus
    {
        return HealthStatus::worst(...array_map(
            static fn (HealthCheckResult $result): HealthStatus => $result->status,
            $this->run(),
        ));
    }

    /**
     * A shallow check for the public endpoint.
     *
     * Only the things whose failure means the site genuinely cannot serve a
     * request. Deliberately cheap: a monitoring system hits this every minute
     * and must not be the reason the database is busy.
     */
    public function shallow(): HealthStatus
    {
        return HealthStatus::worst($this->database()->status, $this->applicationKey()->status);
    }

    private function database(): HealthCheckResult
    {
        return $this->timed('Database', function (): array {
            DB::connection()->select('select 1 as ok');

            return [HealthStatus::Passing, 'Connected and answering queries.'];
        }, 'The database is not reachable. Check the credentials in .env and that the '
            .'database server is running.');
    }

    /**
     * Whether the schema matches the code that is deployed.
     *
     * The most common shared-hosting incident by a distance: files uploaded,
     * migrations forgotten. It presents as scattered 500s that look like a code
     * bug, so it is worth naming precisely.
     */
    private function migrations(): HealthCheckResult
    {
        return $this->timed('Migrations', function (): array {
            if (! $this->migrator->repositoryExists()) {
                return [HealthStatus::Failing, 'No migration table. Run: php artisan migrate --force'];
            }

            $ran = $this->migrator->getRepository()->getRan();

            $pending = collect($this->migrator->getMigrationFiles($this->migrator->paths()
                ?: [database_path('migrations')]))
                ->keys()
                ->reject(static fn (string $file): bool => in_array($file, $ran, true))
                ->values();

            if ($pending->isNotEmpty()) {
                return [
                    HealthStatus::Failing,
                    $pending->count().' migration(s) have not run. Run: php artisan migrate --force',
                ];
            }

            return [HealthStatus::Passing, count($ran).' migrations applied, none pending.'];
        }, 'The migration state could not be read.');
    }

    /**
     * Private media, which must never be reachable over HTTP.
     *
     * Proved by writing and reading back, because a disk that is configured but
     * not writable behaves exactly like a working one until somebody uploads a
     * passport scan.
     */
    private function privateStorage(): HealthCheckResult
    {
        return $this->diskCheck('Private storage', (string) config('filesystems.default', 'local'));
    }

    private function publicStorage(): HealthCheckResult
    {
        return $this->diskCheck('Public storage', 'public');
    }

    private function diskCheck(string $name, string $disk): HealthCheckResult
    {
        return $this->timed($name, function () use ($disk): array {
            $path = 'health/'.Str::random(16).'.txt';
            $token = (string) Str::uuid();

            Storage::disk($disk)->put($path, $token);
            $readBack = Storage::disk($disk)->get($path);
            Storage::disk($disk)->delete($path);

            if ($readBack !== $token) {
                return [HealthStatus::Failing, 'Wrote a file but read back something different.'];
            }

            return [HealthStatus::Passing, 'Readable and writable.'];
        }, 'Could not write to the disk. Check directory permissions.');
    }

    private function cache(): HealthCheckResult
    {
        return $this->timed('Cache', function (): array {
            $key = 'health-check:'.Str::random(12);
            $token = (string) Str::uuid();

            Cache::put($key, $token, 60);
            $readBack = Cache::get($key);
            Cache::forget($key);

            if ($readBack !== $token) {
                return [HealthStatus::Warning, 'The cache did not return what was stored. Sessions and '
                    .'rate limiting may behave oddly.'];
            }

            return [HealthStatus::Passing, 'Storing and returning values.'];
        }, 'The cache store is not usable.');
    }

    /**
     * Whether queued work is actually being picked up.
     *
     * On shared hosting the worker is a short-lived cron job, so the question
     * is never "is a daemon running" but "has anything been drained lately".
     * A backlog that is merely large is a warning; the site still serves.
     */
    private function queue(): HealthCheckResult
    {
        return $this->timed('Queue', function (): array {
            $pending = DB::table('jobs')->count();
            $threshold = (int) config('operations.health.queue_backlog_warning', 250);

            if ($pending === 0) {
                return [HealthStatus::Passing, 'No jobs waiting.'];
            }

            $oldest = DB::table('jobs')->min('available_at');
            // now(), not time(): the application's clock is the one every other
            // timestamp in the system is written against.
            $waitingMinutes = $oldest === null
                ? 0
                : (int) round((now()->getTimestamp() - (int) $oldest) / 60);
            $stallMinutes = (int) config('operations.health.queue_stall_minutes', 30);

            if ($waitingMinutes >= $stallMinutes) {
                return [
                    HealthStatus::Warning,
                    "The oldest job has been waiting {$waitingMinutes} minutes. Check that the queue "
                    .'cron job is running.',
                ];
            }

            if ($pending >= $threshold) {
                return [HealthStatus::Warning, "{$pending} jobs are waiting. The worker may not be keeping up."];
            }

            return [HealthStatus::Passing, "{$pending} job(s) waiting, oldest {$waitingMinutes} minute(s)."];
        }, 'The queue tables could not be read.');
    }

    private function failedJobs(): HealthCheckResult
    {
        return $this->timed('Failed jobs', function (): array {
            $count = DB::table('failed_jobs')->count();

            if ($count === 0) {
                return [HealthStatus::Passing, 'None.'];
            }

            // A failed job is a customer who did not get their confirmation
            // email, so it is never merely informational.
            return [
                HealthStatus::Warning,
                "{$count} failed job(s). Review them in the console — each one is work that did not happen.",
            ];
        }, 'The failed job table could not be read.');
    }

    /**
     * The application key.
     *
     * Its absence is fatal rather than a warning: without it every encrypted
     * column and every signed URL in the database becomes unreadable, and a
     * deployment that generates a *new* one has silently destroyed them.
     */
    private function applicationKey(): HealthCheckResult
    {
        $key = (string) config('app.key');

        if ($key === '') {
            return HealthCheckResult::failing(
                'Application key',
                'APP_KEY is not set. Encrypted data cannot be read. Restore the key from the secret '
                .'store — do not generate a new one on an existing deployment.',
            );
        }

        return HealthCheckResult::passing('Application key', 'Set.');
    }

    /**
     * Whether the scheduler has run recently.
     *
     * The scheduler failing is quiet by nature — reminders simply stop going
     * out — so this reads a heartbeat the scheduled run itself writes.
     */
    private function schedule(): HealthCheckResult
    {
        return $this->timed('Scheduler', function (): array {
            $lastRun = Cache::get('operations:scheduler-heartbeat');

            if (! is_string($lastRun)) {
                return [
                    HealthStatus::Warning,
                    'No scheduler heartbeat recorded yet. If this deployment is new, it appears after '
                    .'the first scheduled minute; otherwise check the cron entry.',
                ];
            }

            $minutes = (int) round((now()->getTimestamp() - (int) strtotime($lastRun)) / 60);
            $limit = (int) config('operations.health.scheduler_stall_minutes', 15);

            if ($minutes > $limit) {
                return [
                    HealthStatus::Failing,
                    "The scheduler last ran {$minutes} minutes ago. Reminders and expiries are not "
                    .'running. Check the cron entry.',
                ];
            }

            return [HealthStatus::Passing, "Last ran {$minutes} minute(s) ago."];
        }, 'The scheduler heartbeat could not be read.');
    }

    /**
     * Runs a check, timing it and turning any throwable into a failure.
     *
     * The exception message is deliberately discarded: a driver's connection
     * error names the host, port, and user, and this output is read in places
     * those do not belong.
     *
     * @param  callable(): array{0: HealthStatus, 1: string}  $callback
     */
    private function timed(string $name, callable $callback, string $failureDetail): HealthCheckResult
    {
        $started = microtime(true);

        try {
            [$status, $detail] = $callback();
        } catch (Throwable $exception) {
            report($exception);

            return HealthCheckResult::failing(
                $name,
                $failureDetail,
                (microtime(true) - $started) * 1000,
            );
        }

        $milliseconds = (microtime(true) - $started) * 1000;

        return match ($status) {
            HealthStatus::Passing => HealthCheckResult::passing($name, $detail, $milliseconds),
            HealthStatus::Warning => HealthCheckResult::warning($name, $detail, $milliseconds),
            HealthStatus::Failing => HealthCheckResult::failing($name, $detail, $milliseconds),
        };
    }
}
