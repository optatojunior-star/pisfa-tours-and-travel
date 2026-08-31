<?php

namespace App\Console\Commands;

use App\Services\Operations\HealthCheck;
use App\Services\Operations\HealthStatus;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Throwable;

/**
 * Checks a deployment before it takes real traffic.
 *
 * Run on the server, after uploading and configuring, before pointing the
 * domain at it. Everything here is a mistake that is easy to make on shared
 * hosting, invisible from the browser, and expensive to discover later:
 * debug mode left on, a session cookie without the Secure flag, a web root
 * pointed at the project instead of public/, a missing key.
 *
 * Read-only. It changes nothing and can be run as often as you like.
 *
 * The exit code is the point: non-zero means do not go live yet, so this can
 * gate a deploy script rather than relying on somebody reading the output.
 */
class ProductionPreflight extends Command
{
    protected $signature = 'pisfa:preflight {--allow-debug : Skip the APP_DEBUG check, for a staging site you intend to debug on}';

    protected $description = 'Check that this deployment is safe to put in front of real users';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    private int $failures = 0;

    private int $warnings = 0;

    public function handle(HealthCheck $health): int
    {
        $this->components->info('PISFA production preflight');

        $this->checkEnvironment();
        $this->checkSecrets();
        $this->checkTransport();
        $this->checkWebRoot();
        $this->checkWritablePaths();
        $this->checkHealth($health);
        $this->checkScheduling();

        $this->table(['', 'Check', 'Result'], $this->rows);

        if ($this->failures > 0) {
            $this->components->error(
                "{$this->failures} check(s) failed. Do not put this deployment in front of users yet."
            );

            return self::FAILURE;
        }

        if ($this->warnings > 0) {
            $this->components->warn("{$this->warnings} warning(s). Safe to launch, but read them.");

            return self::SUCCESS;
        }

        $this->components->info('All checks passed.');

        return self::SUCCESS;
    }

    private function good(string $check, string $detail): void
    {
        $this->rows[] = ['<fg=green>OK</>', $check, $detail];
    }

    private function caution(string $check, string $detail): void
    {
        $this->warnings++;
        $this->rows[] = ['<fg=yellow>WARN</>', $check, $detail];
    }

    private function bad(string $check, string $detail): void
    {
        $this->failures++;
        $this->rows[] = ['<fg=red>FAIL</>', $check, $detail];
    }

    private function checkEnvironment(): void
    {
        $env = (string) config('app.env');

        $env === 'production'
            ? $this->good('APP_ENV', 'production')
            : $this->caution('APP_ENV', "is '{$env}', not 'production'");

        if ($this->option('allow-debug')) {
            $this->caution('APP_DEBUG', 'check skipped by --allow-debug');
        } elseif (config('app.debug')) {
            // Debug pages print the stack trace, the query, and every
            // environment variable in scope. On a public site that is a
            // credential dump behind any 500.
            $this->bad('APP_DEBUG', 'is true — a 500 page would expose configuration and credentials');
        } else {
            $this->good('APP_DEBUG', 'false');
        }

        $url = (string) config('app.url');

        if (! str_starts_with($url, 'https://')) {
            $this->bad('APP_URL', "is '{$url}' — production must be https");
        } elseif (str_contains($url, 'localhost') || str_contains($url, '127.0.0.1')) {
            $this->bad('APP_URL', 'still points at localhost');
        } else {
            $this->good('APP_URL', $url);
        }

        $timezone = (string) config('pisfa.business_timezone', '');

        $timezone === ''
            ? $this->caution('Business timezone', 'not set; times will display in UTC')
            : $this->good('Business timezone', $timezone);
    }

    private function checkSecrets(): void
    {
        $key = (string) config('app.key');

        if ($key === '') {
            $this->bad('APP_KEY', 'not set — encrypted columns and signed URLs will not work');
        } else {
            $this->good('APP_KEY', 'set');
        }

        // A Secure cookie on an https site is what keeps the session out of
        // clear text. Checked against APP_URL rather than APP_ENV, because the
        // scheme is what actually decides whether it matters.
        $https = str_starts_with((string) config('app.url'), 'https://');

        if ($https && ! config('session.secure')) {
            $this->bad(
                'SESSION_SECURE_COOKIE',
                'false on an https site — the session cookie would be sent unencrypted'
            );
        } elseif ($https) {
            $this->good('SESSION_SECURE_COOKIE', 'true');
        } else {
            $this->caution('SESSION_SECURE_COOKIE', 'not applicable while APP_URL is not https');
        }

        config('session.encrypt')
            ? $this->good('SESSION_ENCRYPT', 'true')
            : $this->caution('SESSION_ENCRYPT', 'false — session payloads are stored in the clear');
    }

    private function checkTransport(): void
    {
        $mailer = (string) config('mail.default');

        match (true) {
            $mailer === 'log' => $this->bad('Mail', "is '{$mailer}' — nothing would actually be sent to customers"),
            $mailer === 'array' => $this->bad('Mail', "is '{$mailer}' — mail is discarded"),
            blank(config('mail.from.address')) => $this->bad('Mail', 'no MAIL_FROM_ADDRESS set'),
            default => $this->good('Mail', $mailer.' as '.config('mail.from.address')),
        };

        $queue = (string) config('queue.default');

        $queue === 'sync'
            ? $this->caution('Queue', 'is sync — notifications will send inside the web request and slow it down')
            : $this->good('Queue', $queue);
    }

    /**
     * The single most damaging shared-hosting misconfiguration.
     *
     * If the document root is the project directory rather than `public/`, then
     * `.env`, `storage/`, `vendor/` and `.git/` are all served over HTTP. This
     * cannot be checked from inside PHP with certainty, so it reports what it
     * can see and says plainly what to verify by hand.
     */
    private function checkWebRoot(): void
    {
        $documentRoot = (string) ($_SERVER['DOCUMENT_ROOT'] ?? '');

        if ($documentRoot === '') {
            $this->caution('Document root', 'not visible from the CLI — verify by requesting /.env over HTTP (expect 403/404)');

            return;
        }

        $public = realpath(public_path()) ?: public_path();
        $root = realpath($documentRoot) ?: $documentRoot;

        $root === $public
            ? $this->good('Document root', 'points at public/')
            : $this->bad('Document root', "is {$root}, not {$public} — .env and storage/ may be downloadable");
    }

    private function checkWritablePaths(): void
    {
        foreach (['storage/app', 'storage/framework', 'storage/logs', 'bootstrap/cache'] as $path) {
            $full = base_path($path);

            if (! File::isDirectory($full)) {
                $this->bad('Writable paths', "{$path} does not exist");

                continue;
            }

            if (! is_writable($full)) {
                $this->bad('Writable paths', "{$path} is not writable by the web user");

                continue;
            }
        }

        if ($this->failures === 0 || ! collect($this->rows)->contains(fn (array $r): bool => $r[1] === 'Writable paths')) {
            $this->good('Writable paths', 'storage/ and bootstrap/cache are writable');
        }

        // The public disk is served from the web root, so a missing symlink
        // means every uploaded image 404s while the admin console shows it fine.
        $link = public_path('storage');

        if (! file_exists($link)) {
            $this->caution('Storage symlink', 'public/storage is missing — run: php artisan storage:link');
        } else {
            $this->good('Storage symlink', 'present');
        }
    }

    private function checkHealth(HealthCheck $health): void
    {
        foreach ($health->run() as $result) {
            match ($result->status) {
                HealthStatus::Passing => $this->good($result->name, $result->detail),
                HealthStatus::Warning => $this->caution($result->name, $result->detail),
                HealthStatus::Failing => $this->bad($result->name, $result->detail),
            };
        }
    }

    private function checkScheduling(): void
    {
        // Proves the routes actually resolve on this deployment, which catches
        // a bad APP_URL or a missing route cache before a customer meets it.
        foreach (['home', 'health', 'pwa.manifest'] as $name) {
            if (! Route::has($name)) {
                $this->bad('Routes', "the '{$name}' route is missing — is the route cache stale?");

                return;
            }
        }

        $this->good('Routes', 'home, health and manifest all resolve');

        try {
            $count = count(app(Schedule::class)->events());

            $count > 0
                ? $this->good('Scheduled tasks', "{$count} registered — confirm cron runs schedule:run every minute")
                : $this->caution('Scheduled tasks', 'none registered');
        } catch (Throwable) {
            $this->caution('Scheduled tasks', 'could not be inspected');
        }
    }
}
