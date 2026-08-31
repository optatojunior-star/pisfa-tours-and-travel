<?php

namespace App\Console\Commands;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\VehicleMaintenanceRecord;
use App\Notifications\Fleet\FleetAlertNotification;
use App\Services\Fleet\FleetReport;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;

/**
 * Warns the team about service that is due and paperwork that is expiring.
 *
 * Covers three compliance surfaces: service due, vehicle paperwork, and driver
 * licences. Each is marked once reported, so a daily sweep does not send the
 * same warning every morning until somebody acts.
 */
class SendFleetAlerts extends Command
{
    protected $signature = 'fleet:send-alerts';

    protected $description = 'Report vehicles with service due and documents expiring';

    public function handle(FleetReport $report): int
    {
        $now = CarbonImmutable::now();
        $withinDays = (int) config('fleet.alerts.document_notice_days', 30);

        $serviceDue = $this->serviceDue($now);
        $documents = $this->expiringDocuments($report, $withinDays, $now);
        $licences = $this->expiringLicences($report, $withinDays, $now);

        if ($serviceDue === [] && $documents === [] && $licences === []) {
            $this->components->info('Fleet alerts: nothing due.');

            return self::SUCCESS;
        }

        $recipients = User::query()
            ->where('status', AccountStatus::Active->value)
            ->whereIn('role', [UserRole::Manager->value, UserRole::SuperAdmin->value])
            ->get();

        if ($recipients->isEmpty()) {
            // Reported rather than swallowed: an alert with nobody to receive it
            // is a configuration problem, not a quiet success.
            $this->components->warn('Fleet alerts: '.count($serviceDue).' service due, '
                .count($documents).' document(s) and '.count($licences)
                .' licence(s) expiring, but no manager account exists to notify.');

            return self::SUCCESS;
        }

        NotificationFacade::send($recipients, new FleetAlertNotification(
            serviceDue: $serviceDue,
            documents: $documents,
            consoleUrl: route('admin.fleet.index'),
            licences: $licences,
        ));

        $this->markReported($serviceDue, $documents, $licences);

        $this->components->info('Fleet alerts: '.count($serviceDue).' service due, '
            .count($documents).' document(s) and '.count($licences).' licence(s) expiring, sent to '
            .$recipients->count().' recipient(s).');

        return self::SUCCESS;
    }

    /**
     * @return list<array{vehicle: string, plate: string, reason: string, id: int}>
     */
    private function serviceDue(CarbonImmutable $now): array
    {
        $rows = [];

        VehicleMaintenanceRecord::query()
            ->due($now)
            ->whereNull('due_alert_sent_at')
            ->with('vehicle')
            ->orderBy('id')
            ->chunkById(100, function ($records) use (&$rows, $now): void {
                foreach ($records as $record) {
                    $vehicle = $record->vehicle;

                    if ($vehicle === null) {
                        continue;
                    }

                    $reason = $record->dueReason($now);

                    if ($reason === null) {
                        continue;
                    }

                    $rows[] = [
                        'id' => (int) $record->getKey(),
                        'vehicle' => trim($vehicle->make.' '.$vehicle->model),
                        'plate' => $vehicle->registration_plate,
                        'reason' => $record->type->label().' '.$reason,
                    ];
                }
            });

        return $rows;
    }

    /**
     * @return list<array{id: int, vehicle: string, plate: string, document: string, expires: string, expired: bool}>
     */
    private function expiringDocuments(FleetReport $report, int $withinDays, CarbonImmutable $now): array
    {
        $rows = [];

        foreach ($report->expiringDocuments($withinDays, $now, unreportedOnly: true) as $entry) {
            $vehicle = $entry['vehicle'];

            if ($vehicle === null) {
                continue;
            }

            $rows[] = [
                'id' => (int) $entry['document_id'],
                'vehicle' => trim($vehicle->make.' '.$vehicle->model),
                'plate' => $vehicle->registration_plate,
                'document' => $entry['document']->category->label(),
                'expires' => $entry['expires_at']?->format('j M Y') ?? 'unknown',
                'expired' => (bool) $entry['has_expired'],
            ];
        }

        return $rows;
    }

    /**
     * @return list<array{id: int, driver: string, expires: string, expired: bool}>
     */
    private function expiringLicences(FleetReport $report, int $withinDays, CarbonImmutable $now): array
    {
        $rows = [];

        foreach ($report->expiringLicences($withinDays, $now, unreportedOnly: true) as $entry) {
            $rows[] = [
                'id' => (int) $entry['profile_id'],
                'driver' => $entry['driver'],
                'expires' => $entry['expires_at']?->format('j M Y') ?? 'unknown',
                'expired' => (bool) $entry['has_expired'],
            ];
        }

        return $rows;
    }

    /**
     * Marks everything just reported, so tomorrow's sweep stays quiet until the
     * repeat window elapses or somebody acts.
     *
     * @param  list<array{id: int}>  $serviceDue
     * @param  list<array{id: int}>  $documents
     * @param  list<array{id: int}>  $licences
     */
    private function markReported(array $serviceDue, array $documents, array $licences): void
    {
        $maintenanceIds = array_column($serviceDue, 'id');

        if ($maintenanceIds !== []) {
            DB::table('vehicle_maintenance_records')
                ->whereIn('id', $maintenanceIds)
                ->update(['due_alert_sent_at' => now(), 'updated_at' => now()]);
        }

        $documentIds = array_column($documents, 'id');

        if ($documentIds !== []) {
            DB::table('documents')
                ->whereIn('id', $documentIds)
                ->update(['expiry_alert_sent_at' => now(), 'updated_at' => now()]);
        }

        $licenceIds = array_column($licences, 'id');

        if ($licenceIds !== []) {
            DB::table('driver_profiles')
                ->whereIn('id', $licenceIds)
                ->update(['expiry_alert_sent_at' => now(), 'updated_at' => now()]);
        }
    }
}
