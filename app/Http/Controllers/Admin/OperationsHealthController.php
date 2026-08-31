<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Operations\HealthCheck;
use App\Services\Operations\HealthCheckResult;
use App\Services\Operations\HealthStatus;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

/**
 * The operations console.
 *
 * Everything an operator needs to answer "is this deployment healthy" without
 * SSH: the detailed health report, the failed jobs queue with retry, and the
 * backup history.
 *
 * Restricted to super administrators. The health detail names which subsystem
 * is failing and the failed-job list carries exception messages, neither of
 * which belongs in front of ordinary staff.
 */
class OperationsHealthController extends Controller
{
    public function index(HealthCheck $health): View
    {
        $this->authorize('viewOperations', User::class);

        $results = $health->run();

        return view('admin.operations.index', [
            'results' => $results,
            'overall' => HealthStatus::worst(...array_map(
                static fn (HealthCheckResult $result): HealthStatus => $result->status,
                $results,
            )),
            'failedJobs' => DB::table('failed_jobs')
                ->orderByDesc('id')
                ->limit(25)
                ->get()
                ->map(fn (object $job): array => $this->presentFailedJob($job))
                ->all(),
            'failedJobCount' => DB::table('failed_jobs')->count(),
            'backups' => $this->recentBackups(),
        ]);
    }

    /**
     * Retries one failed job.
     *
     * `queue:retry` pushes it back rather than running it here: a job that
     * failed on a timeout would fail the same way inside a web request, and
     * take the operator's page down with it.
     */
    public function retry(Request $request, string $uuid, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize('viewOperations', User::class);

        $job = DB::table('failed_jobs')->where('uuid', $uuid)->first();

        abort_if($job === null, 404);

        Artisan::call('queue:retry', ['id' => [$uuid]]);

        $auditLogger->record(
            event: 'operations.failed_job_retried',
            // The payload is not recorded: it can contain a customer's details,
            // and the audit trail has a different readership from the queue.
            newValues: ['uuid' => $uuid, 'queue' => $job->queue ?? null],
            user: $request->user(),
        );

        return back()->with('success', 'The job has been pushed back onto the queue.');
    }

    public function forget(Request $request, string $uuid, AuditLogger $auditLogger): RedirectResponse
    {
        $this->authorize('viewOperations', User::class);

        $deleted = DB::table('failed_jobs')->where('uuid', $uuid)->delete();

        abort_if($deleted === 0, 404);

        $auditLogger->record(
            event: 'operations.failed_job_discarded',
            newValues: ['uuid' => $uuid],
            user: $request->user(),
        );

        return back()->with('success', 'The failed job has been discarded.');
    }

    /**
     * @return array<string, mixed>
     */
    private function presentFailedJob(object $job): array
    {
        $payload = json_decode((string) ($job->payload ?? '{}'), true);
        $name = is_array($payload) ? ($payload['displayName'] ?? 'Unknown job') : 'Unknown job';

        // Only the first line of the exception. The full trace names file paths
        // and can quote arguments, and the first line is what identifies the
        // problem anyway.
        $exception = (string) ($job->exception ?? '');
        $firstLine = strtok($exception, "\n");

        return [
            'uuid' => (string) ($job->uuid ?? ''),
            'name' => is_string($name) ? $name : 'Unknown job',
            'queue' => (string) ($job->queue ?? 'default'),
            'failed_at' => $job->failed_at ?? null,
            'reason' => $firstLine === false ? 'No reason recorded.' : mb_substr($firstLine, 0, 300),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentBackups(): array
    {
        $disk = (string) config('operations.backup.disk', 'local');
        $prefix = trim((string) config('operations.backup.path', 'backups'), '/');

        if (! Storage::disk($disk)->exists($prefix)) {
            return [];
        }

        return collect(Storage::disk($disk)->files($prefix))
            ->sortDesc()
            ->take(10)
            ->map(static fn (string $file): array => [
                'name' => basename($file),
                'bytes' => Storage::disk($disk)->size($file),
                'at' => Storage::disk($disk)->lastModified($file),
            ])
            ->values()
            ->all();
    }
}
