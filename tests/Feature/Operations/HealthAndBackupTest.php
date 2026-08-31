<?php

namespace Tests\Feature\Operations;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Http\Middleware\EnsureTwoFactorAuthenticationIsConfigured;
use App\Logging\ConfigureLogging;
use App\Logging\RedactSensitiveValues;
use App\Models\User;
use App\Services\Operations\BackupRunner;
use App\Services\Operations\DatabaseDump;
use App\Services\Operations\HealthCheck;
use App\Services\Operations\HealthCheckResult;
use App\Services\Operations\HealthStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Monolog\Level;
use Monolog\LogRecord;
use Tests\TestCase;

class HealthAndBackupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
    }

    private function superAdmin(): User
    {
        return User::factory()->create([
            'role' => UserRole::SuperAdmin,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);
    }

    private function staff(): User
    {
        return User::factory()->create([
            'role' => UserRole::Staff,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);
    }

    // ---------------------------------------------------------------------
    // The public endpoint
    // ---------------------------------------------------------------------

    public function test_the_public_health_endpoint_reports_ok(): void
    {
        $this->getJson(route('health'))
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }

    public function test_the_public_endpoint_names_no_subsystem(): void
    {
        // A public endpoint that lists what it checked tells an attacker what
        // to aim at, and one that reports a connection error names the host.
        $response = $this->getJson(route('health'));

        $body = $response->getContent();

        $this->assertIsString($body);
        $this->assertStringNotContainsStringIgnoringCase('database', $body);
        $this->assertStringNotContainsStringIgnoringCase('queue', $body);
        $this->assertStringNotContainsStringIgnoringCase('storage', $body);
        $this->assertSame(['status'], array_keys($response->json()));
    }

    public function test_the_public_endpoint_needs_no_authentication(): void
    {
        $this->getJson(route('health'))->assertOk();
    }

    // ---------------------------------------------------------------------
    // The checks themselves
    // ---------------------------------------------------------------------

    public function test_a_healthy_deployment_passes_every_check(): void
    {
        Cache::put('operations:scheduler-heartbeat', now()->toIso8601String(), now()->addDay());

        $results = app(HealthCheck::class)->run();

        $failing = array_filter(
            $results,
            static fn ($result): bool => $result->status === HealthStatus::Failing,
        );

        $this->assertSame([], array_map(static fn ($r): string => $r->name, $failing));
    }

    public function test_every_check_reports_a_name_and_a_detail(): void
    {
        foreach (app(HealthCheck::class)->run() as $result) {
            $this->assertNotSame('', $result->name);
            $this->assertNotSame('', $result->detail);
        }
    }

    public function test_a_missing_scheduler_heartbeat_is_a_warning_not_a_failure(): void
    {
        Cache::forget('operations:scheduler-heartbeat');

        $scheduler = $this->check('Scheduler');

        // A brand new deployment has no heartbeat yet. Reporting that as down
        // would have a monitoring system restart a working site.
        $this->assertSame(HealthStatus::Warning, $scheduler->status);
    }

    public function test_a_stale_scheduler_heartbeat_fails(): void
    {
        Cache::put(
            'operations:scheduler-heartbeat',
            now()->subHours(3)->toIso8601String(),
            now()->addDay(),
        );

        $this->assertSame(HealthStatus::Failing, $this->check('Scheduler')->status);
    }

    public function test_a_fresh_scheduler_heartbeat_passes(): void
    {
        Cache::put('operations:scheduler-heartbeat', now()->toIso8601String(), now()->addDay());

        $this->assertSame(HealthStatus::Passing, $this->check('Scheduler')->status);
    }

    public function test_failed_jobs_are_a_warning_because_they_are_work_that_did_not_happen(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\Example'], JSON_THROW_ON_ERROR),
            'exception' => "RuntimeException: something broke\n#0 /app/file.php(1)",
            'failed_at' => now(),
        ]);

        $this->assertSame(HealthStatus::Warning, $this->check('Failed jobs')->status);
    }

    public function test_a_stalled_queue_is_reported(): void
    {
        DB::table('jobs')->insert([
            'queue' => 'default',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subHours(2)->getTimestamp(),
            'created_at' => now()->subHours(2)->getTimestamp(),
        ]);

        $queue = $this->check('Queue');

        $this->assertSame(HealthStatus::Warning, $queue->status);
        $this->assertStringContainsString('cron', mb_strtolower($queue->detail));
    }

    public function test_storage_is_proved_by_writing_and_reading_back(): void
    {
        // Not by reading configuration: a disk that is configured but not
        // writable behaves exactly like a working one until somebody uploads a
        // passport scan.
        $this->assertSame(HealthStatus::Passing, $this->check('Private storage')->status);
        $this->assertSame(HealthStatus::Passing, $this->check('Public storage')->status);
    }

    public function test_the_storage_check_leaves_nothing_behind(): void
    {
        Storage::fake('local');

        app(HealthCheck::class)->run();

        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_missing_application_key_is_fatal(): void
    {
        // Without it every encrypted column and signed URL in the database is
        // unreadable, so this is never merely a warning.
        config()->set('app.key', '');

        $this->assertSame(HealthStatus::Failing, $this->check('Application key')->status);
        $this->assertTrue(app(HealthCheck::class)->shallow()->isDown());
    }

    public function test_the_endpoint_reports_down_when_a_fatal_check_fails(): void
    {
        // Stubbed rather than actually clearing APP_KEY: an empty key makes the
        // encrypter throw while building the response, so the request would
        // never reach the controller this test is about.
        $this->instance(HealthCheck::class, new class(app('migrator')) extends HealthCheck
        {
            public function shallow(): HealthStatus
            {
                return HealthStatus::Failing;
            }
        });

        $this->getJson(route('health'))
            ->assertStatus(503)
            ->assertExactJson(['status' => 'down']);
    }

    private function check(string $name): HealthCheckResult
    {
        foreach (app(HealthCheck::class)->run() as $result) {
            if ($result->name === $name) {
                return $result;
            }
        }

        $this->fail("No health check named {$name}.");
    }

    // ---------------------------------------------------------------------
    // The console
    // ---------------------------------------------------------------------

    public function test_the_operations_console_is_closed_to_ordinary_staff(): void
    {
        $this->withoutMiddleware(EnsureTwoFactorAuthenticationIsConfigured::class);

        $this->actingAs($this->staff())
            ->get(route('admin.operations.index'))
            ->assertForbidden();
    }

    public function test_a_super_administrator_sees_the_detailed_report(): void
    {
        $this->withoutMiddleware(EnsureTwoFactorAuthenticationIsConfigured::class);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.operations.index'))
            ->assertOk()
            ->assertSee('Health checks')
            ->assertSee('Database');
    }

    public function test_a_failed_job_can_be_discarded_from_the_console(): void
    {
        $this->withoutMiddleware(EnsureTwoFactorAuthenticationIsConfigured::class);

        $uuid = (string) Str::uuid();

        DB::table('failed_jobs')->insert([
            'uuid' => $uuid,
            'connection' => 'database',
            'queue' => 'default',
            'payload' => json_encode(['displayName' => 'App\\Jobs\\Example'], JSON_THROW_ON_ERROR),
            'exception' => 'RuntimeException: broke',
            'failed_at' => now(),
        ]);

        $this->actingAs($this->superAdmin())
            ->delete(route('admin.operations.failed-jobs.forget', $uuid))
            ->assertRedirect();

        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_discarding_a_job_that_does_not_exist_is_a_404(): void
    {
        $this->withoutMiddleware(EnsureTwoFactorAuthenticationIsConfigured::class);

        $this->actingAs($this->superAdmin())
            ->delete(route('admin.operations.failed-jobs.forget', (string) Str::uuid()))
            ->assertNotFound();
    }

    // ---------------------------------------------------------------------
    // Backups
    // ---------------------------------------------------------------------

    public function test_the_dump_contains_the_schema_and_the_rows(): void
    {
        $this->superAdmin();

        $sql = '';

        foreach (app(DatabaseDump::class)->stream() as $chunk) {
            $sql .= $chunk;
        }

        $this->assertStringContainsString('CREATE TABLE', mb_strtoupper($sql));
        $this->assertStringContainsString('INSERT INTO', mb_strtoupper($sql));
        $this->assertStringContainsString('users', $sql);
    }

    public function test_the_dump_shells_out_to_nothing(): void
    {
        // The whole point of DatabaseDump: shared hosts disable exec(), and a
        // backup that silently fails to run is worse than none because it is
        // believed. If this class ever grows a process call, this test is the
        // one that should be argued with rather than deleted.
        $source = file_get_contents(app_path('Services/Operations/DatabaseDump.php'));

        $this->assertIsString($source);

        // Comments are stripped first, so the class may explain *why* it avoids
        // these calls without the explanation tripping the check.
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        foreach (['exec', 'shell_exec', 'proc_open', 'passthru', 'popen', 'system'] as $call) {
            $this->assertStringNotContainsString($call.'(', $code);
        }
    }

    public function test_a_backup_writes_a_dump_to_the_configured_disk(): void
    {
        Storage::fake('local');
        config()->set('operations.backup.disk', 'local');

        $result = app(BackupRunner::class)->run(includeMedia: false);

        Storage::disk('local')->assertExists($result['database']);
        $this->assertGreaterThan(0, $result['bytes']);
    }

    public function test_a_backup_never_contains_the_environment_file(): void
    {
        Storage::fake('local');
        config()->set('operations.backup.disk', 'local');

        $result = app(BackupRunner::class)->run(includeMedia: false);

        $contents = Storage::disk('local')->get($result['database']);

        // An archive holding both the encrypted data and APP_KEY is one file
        // that gives away everything.
        $this->assertIsString($contents);
        $this->assertStringNotContainsString('APP_KEY', $contents);
        $this->assertStringNotContainsString((string) config('app.key'), $contents);
    }

    public function test_retention_never_deletes_the_only_backup(): void
    {
        Storage::fake('local');
        config()->set('operations.backup.disk', 'local');
        config()->set('operations.backup.keep', 1);
        config()->set('operations.backup.max_age_days', 1);

        Storage::disk('local')->put('backups/db-2020-01-01_000000.sql', 'old');

        $deleted = app(BackupRunner::class)->prune();

        // A misconfigured age limit, or a clock that jumped, must not be able
        // to leave the deployment with nothing to restore from.
        $this->assertSame([], $deleted);
        Storage::disk('local')->assertExists('backups/db-2020-01-01_000000.sql');
    }

    public function test_retention_removes_older_backups_beyond_the_keep_count(): void
    {
        Storage::fake('local');
        config()->set('operations.backup.disk', 'local');
        config()->set('operations.backup.keep', 2);

        foreach (['2026-08-01', '2026-08-02', '2026-08-03', '2026-08-04'] as $day) {
            Storage::disk('local')->put("backups/db-{$day}_000000.sql", 'x');
        }

        $deleted = app(BackupRunner::class)->prune();

        $this->assertCount(2, $deleted);
        Storage::disk('local')->assertExists('backups/db-2026-08-04_000000.sql');
        Storage::disk('local')->assertMissing('backups/db-2026-08-01_000000.sql');
    }

    public function test_the_backup_command_runs_and_reports(): void
    {
        Storage::fake('local');
        config()->set('operations.backup.disk', 'local');

        $this->artisan('pisfa:backup', ['--no-media' => true])
            ->assertSuccessful();
    }

    public function test_the_heartbeat_command_writes_the_value_the_check_reads(): void
    {
        Cache::forget('operations:scheduler-heartbeat');

        $this->artisan('pisfa:heartbeat')->assertSuccessful();

        $this->assertSame(HealthStatus::Passing, $this->check('Scheduler')->status);
    }

    // ---------------------------------------------------------------------
    // Log redaction
    // ---------------------------------------------------------------------

    /** @param array<string, mixed> $context */
    private function redactContext(array $context): array
    {
        $record = new LogRecord(
            datetime: now()->toDateTimeImmutable(),
            channel: 'testing',
            level: Level::Info,
            message: 'test',
            context: $context,
        );

        return (new RedactSensitiveValues)($record)->context;
    }

    public function test_secrets_are_stripped_from_log_context(): void
    {
        $redacted = $this->redactContext([
            'password' => 'hunter2',
            'api_key' => 'sk_live_abc',
            'authorization' => 'Bearer abc',
            'stripe_secret_key' => 'sk_test',
            'WHATSAPP_ACCESS_TOKEN' => 'EAAG',
            'signature' => 'sha256=abc',
            'booking_reference' => 'BKG-123',
        ]);

        foreach (['password', 'api_key', 'authorization', 'stripe_secret_key', 'WHATSAPP_ACCESS_TOKEN', 'signature'] as $key) {
            $this->assertSame(RedactSensitiveValues::REDACTED, $redacted[$key], "{$key} was not redacted");
        }

        // Operational context stays: an operator has to be able to trace a
        // booking, and the reference is already in a database they can read.
        $this->assertSame('BKG-123', $redacted['booking_reference']);
    }

    public function test_redaction_reaches_nested_context(): void
    {
        $redacted = $this->redactContext([
            'request' => ['headers' => ['Authorization' => 'Bearer abc'], 'path' => '/checkout'],
        ]);

        $this->assertSame(RedactSensitiveValues::REDACTED, $redacted['request']['headers']['Authorization']);
        $this->assertSame('/checkout', $redacted['request']['path']);
    }

    public function test_redaction_matches_key_names_however_they_are_written(): void
    {
        $redacted = $this->redactContext([
            'API-KEY' => 'a',
            'api key' => 'b',
            'Api.Key' => 'c',
            'card_number' => 'd',
        ]);

        foreach (array_keys($redacted) as $key) {
            $this->assertSame(RedactSensitiveValues::REDACTED, $redacted[$key]);
        }
    }

    public function test_redaction_does_not_recurse_without_bound(): void
    {
        $deep = ['password' => 'secret'];

        for ($i = 0; $i < 40; $i++) {
            $deep = ['level' => $deep];
        }

        // A stack overflow inside the logger would take down the request that
        // was trying to report a problem, so recursion is bounded. The
        // structure still comes back, and anything within the bound is still
        // redacted — the bound limits how deep it looks, not whether it works.
        $redacted = $this->redactContext(['api_key' => 'sk_live', 'deep' => $deep]);

        $this->assertSame(RedactSensitiveValues::REDACTED, $redacted['api_key']);
        $this->assertArrayHasKey('level', $redacted['deep']);
    }

    public function test_every_file_writing_log_channel_redacts(): void
    {
        foreach (['single', 'daily', 'slack', 'syslog', 'errorlog', 'emergency'] as $channel) {
            $this->assertSame(
                [ConfigureLogging::class],
                config("logging.channels.{$channel}.tap"),
                "The {$channel} channel does not redact.",
            );
        }

        foreach (['stderr', 'papertrail'] as $channel) {
            $this->assertContains(
                RedactSensitiveValues::class,
                config("logging.channels.{$channel}.processors", []),
                "The {$channel} channel does not redact.",
            );
        }
    }
}
