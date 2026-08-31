@php
    $timezone = config('pisfa.business_timezone', 'Africa/Kampala');

    $humanBytes = static function (int $bytes): string {
        foreach (['B', 'KB', 'MB', 'GB'] as $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return round($bytes, 1).' '.$unit;
            }
            $bytes = (int) round($bytes / 1024);
        }
        return $bytes.' B';
    };
@endphp
<x-app-layout>
    <x-slot name="header">
        <div class="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
            <div>
                <p class="text-xs font-bold uppercase tracking-[0.16em] text-emerald-700">Governance</p>
                <h1 class="mt-1 text-2xl font-bold text-slate-950">Operations</h1>
            </div>
            <span class="inline-flex items-center rounded-full bg-{{ $overall->tone() }}-100 px-4 py-2 text-sm font-bold text-{{ $overall->tone() }}-800">
                {{ $overall->label() }}
            </span>
        </div>
    </x-slot>

    <div class="py-8">
        <div class="mx-auto max-w-5xl space-y-6 px-4 sm:px-6 lg:px-8">
            @if (session('success'))
                <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm font-semibold text-emerald-900" role="status">{{ session('success') }}</div>
            @endif

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white" aria-labelledby="health-heading">
                <h2 id="health-heading" class="border-b border-slate-200 px-5 py-4 text-sm font-bold uppercase tracking-wide text-slate-600">
                    Health checks
                </h2>
                <ul class="divide-y divide-slate-100">
                    @foreach ($results as $result)
                        <li class="flex flex-col gap-2 px-5 py-4 sm:flex-row sm:items-center sm:justify-between">
                            <div class="min-w-0">
                                <p class="font-bold text-slate-900">{{ $result->name }}</p>
                                <p class="text-sm text-slate-600">{{ $result->detail }}</p>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                @if ($result->milliseconds !== null)
                                    <span class="text-xs text-slate-500">{{ round($result->milliseconds, 1) }} ms</span>
                                @endif
                                <span class="inline-flex rounded-full bg-{{ $result->status->tone() }}-100 px-2.5 py-1 text-xs font-bold text-{{ $result->status->tone() }}-800">
                                    {{ $result->status->label() }}
                                </span>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white" aria-labelledby="failed-heading">
                <h2 id="failed-heading" class="border-b border-slate-200 px-5 py-4 text-sm font-bold uppercase tracking-wide text-slate-600">
                    Failed jobs
                    @if ($failedJobCount > 0)
                        <span class="ml-2 rounded-full bg-rose-100 px-2 py-0.5 text-xs text-rose-800">{{ $failedJobCount }}</span>
                    @endif
                </h2>

                @if ($failedJobs === [])
                    <p class="px-5 py-8 text-center text-sm text-slate-600">Nothing has failed. Every queued job has run.</p>
                @else
                    <p class="border-b border-slate-100 bg-amber-50 px-5 py-3 text-sm text-amber-900">
                        Each of these is work that did not happen — a confirmation email that never arrived,
                        a document that was never filed. Fix the cause, then retry.
                    </p>
                    <ul class="divide-y divide-slate-100">
                        @foreach ($failedJobs as $job)
                            <li class="px-5 py-4">
                                <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div class="min-w-0">
                                        <p class="font-bold text-slate-900">{{ $job['name'] }}</p>
                                        <p class="mt-1 break-words text-sm text-rose-800">{{ $job['reason'] }}</p>
                                        <p class="mt-1 text-xs text-slate-500">
                                            Queue: {{ $job['queue'] }}
                                            @if ($job['failed_at'])
                                                · Failed {{ \Illuminate\Support\Carbon::parse($job['failed_at'])->setTimezone($timezone)->format('D j M, H:i') }}
                                            @endif
                                        </p>
                                    </div>
                                    <div class="flex shrink-0 gap-2">
                                        <form method="POST" action="{{ route('admin.operations.failed-jobs.retry', $job['uuid']) }}">
                                            @csrf
                                            <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl bg-emerald-700 px-4 py-2 text-sm font-bold text-white hover:bg-emerald-800">
                                                Retry
                                            </button>
                                        </form>
                                        <form method="POST" action="{{ route('admin.operations.failed-jobs.forget', $job['uuid']) }}"
                                              onsubmit="return confirm('Discard this failed job? The work it represents will not happen.');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="inline-flex min-h-11 items-center justify-center rounded-xl border border-rose-300 px-4 py-2 text-sm font-bold text-rose-700 hover:bg-rose-50">
                                                Discard
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section class="overflow-hidden rounded-2xl border border-slate-200 bg-white" aria-labelledby="backups-heading">
                <h2 id="backups-heading" class="border-b border-slate-200 px-5 py-4 text-sm font-bold uppercase tracking-wide text-slate-600">
                    Recent backups
                </h2>

                @if ($backups === [])
                    <p class="px-5 py-8 text-center text-sm text-slate-600">
                        No backups found. They run nightly; run <code class="rounded bg-slate-100 px-1">php artisan pisfa:backup</code> to take one now.
                    </p>
                @else
                    <ul class="divide-y divide-slate-100">
                        @foreach ($backups as $backup)
                            <li class="flex items-center justify-between px-5 py-3 text-sm">
                                <span class="font-semibold text-slate-800">{{ $backup['name'] }}</span>
                                <span class="text-slate-600">
                                    {{ $humanBytes($backup['bytes']) }}
                                    · {{ \Illuminate\Support\Carbon::createFromTimestamp($backup['at'])->setTimezone($timezone)->format('D j M, H:i') }}
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif

                @if (config('operations.backup.disk') === 'local')
                    <p class="border-t border-slate-100 bg-amber-50 px-5 py-3 text-sm text-amber-900">
                        These backups are on the same server as the database. That protects against a bad
                        migration, not against losing the server. Set <code class="rounded bg-amber-100 px-1">PISFA_BACKUP_DISK</code>
                        to a remote disk, or copy them off on a schedule.
                    </p>
                @endif
            </section>
        </div>
    </div>
</x-app-layout>
