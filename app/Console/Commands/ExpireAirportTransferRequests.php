<?php

namespace App\Console\Commands;

use App\Enums\AirportTransferBookingStatus;
use App\Enums\AirportTransferEventType;
use App\Models\AirportTransferAssignment;
use App\Models\AirportTransferBooking;
use App\Models\AirportTransferEvent;
use App\Notifications\AirportTransfers\AirportTransferBookingStatusNotification;
use App\Notifications\AirportTransfers\AirportTransferTeamAssignmentNotification;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Throwable;

class ExpireAirportTransferRequests extends Command
{
    protected $signature = 'airport-transfers:expire-pending-requests';

    protected $description = 'Expire pending airport-transfer requests whose persisted deadline has passed';

    public function handle(AuditLogger $auditLogger): int
    {
        $now = now();
        $expired = 0;

        AirportTransferBooking::query()
            ->expiredRequests($now)
            ->orderBy('id')
            ->select('id')
            ->chunkById(100, function ($bookings) use ($auditLogger, $now, &$expired): void {
                foreach ($bookings as $candidate) {
                    $changed = DB::transaction(function () use ($auditLogger, $candidate, $now): bool {
                        $booking = AirportTransferBooking::query()
                            ->whereKey($candidate->getKey())
                            ->lockForUpdate()
                            ->first();

                        if ($booking === null
                            || $booking->status !== AirportTransferBookingStatus::Pending
                            || $booking->request_expires_at->isAfter($now)) {
                            return false;
                        }

                        $assignment = AirportTransferAssignment::query()
                            ->forBooking($booking)
                            ->active()
                            ->lockForUpdate()
                            ->first();

                        if ($assignment !== null) {
                            $assignment->forceFill([
                                'unassigned_at' => $now,
                                'unassigned_by_user_id' => null,
                                'unassignment_reason' => 'The pending request expired before confirmation.',
                            ])->save();
                        }

                        $booking->forceFill([
                            'status' => AirportTransferBookingStatus::Expired,
                            'expired_at' => $now,
                            'assigned_vehicle_id' => null,
                            'assigned_driver_user_id' => null,
                        ])->save();

                        AirportTransferEvent::query()->firstOrCreate(
                            [
                                'airport_transfer_booking_id' => $booking->getKey(),
                                'event_type' => AirportTransferEventType::BookingExpired->value,
                            ],
                            [
                                'payload' => [
                                    'schema_version' => 1,
                                    'booking_reference' => $booking->reference,
                                    'request_expired_at' => $booking->request_expires_at->toIso8601String(),
                                    'assignment_id' => $assignment?->getKey(),
                                ],
                                'processed_at' => null,
                            ],
                        );

                        $auditLogger->record(
                            event: 'airport_transfer.booking_expired',
                            auditable: $booking,
                            oldValues: [
                                'status' => AirportTransferBookingStatus::Pending->value,
                                'assigned_vehicle_id' => $assignment?->vehicle_id,
                                'assigned_driver_user_id' => $assignment?->driver_user_id,
                            ],
                            newValues: [
                                'status' => AirportTransferBookingStatus::Expired->value,
                                'assigned_vehicle_id' => null,
                                'assigned_driver_user_id' => null,
                            ],
                            context: ['url' => 'console:airport-transfers:expire-pending-requests'],
                        );

                        return true;
                    }, 3);

                    $expired += $changed ? 1 : 0;
                }
            });

        [$dispatched, $failures] = $this->dispatchUnprocessedExpiryNotifications();

        $this->components->info(
            "Airport-transfer requests: {$expired} expired; {$dispatched} notification set(s) queued; {$failures} failure(s).",
        );

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }

    /** @return array{int, int} */
    private function dispatchUnprocessedExpiryNotifications(): array
    {
        $dispatched = 0;
        $failures = 0;

        AirportTransferEvent::query()
            ->where('event_type', AirportTransferEventType::BookingExpired->value)
            ->unprocessed()
            ->orderBy('id')
            ->select('id')
            ->chunkById(100, function ($events) use (&$dispatched, &$failures): void {
                foreach ($events as $candidate) {
                    try {
                        $queued = DB::transaction(function () use ($candidate): bool {
                            $event = AirportTransferEvent::query()
                                ->with([
                                    'booking.customer',
                                    'booking.assignments' => fn ($query) => $query
                                        ->with(['driver', 'vehicle'])
                                        ->orderByDesc('assigned_at')
                                        ->orderByDesc('id'),
                                ])
                                ->whereKey($candidate->getKey())
                                ->lockForUpdate()
                                ->first();

                            if ($event === null || $event->processed_at !== null) {
                                return false;
                            }

                            $booking = $event->booking;

                            if ($booking === null || $booking->status !== AirportTransferBookingStatus::Expired) {
                                return false;
                            }

                            $viewUrl = $this->customerViewUrl($booking);
                            $notification = new AirportTransferBookingStatusNotification(
                                recipientName: $booking->contact_name,
                                bookingReference: $booking->reference,
                                statusLabel: AirportTransferBookingStatus::Expired->label(),
                                serviceStartsAt: $booking->service_starts_at->toIso8601String(),
                                viewUrl: $viewUrl,
                                customerReason: 'The request was not confirmed before its review deadline.',
                            );

                            if ($booking->customer !== null) {
                                $booking->customer->notify($notification);
                            } else {
                                Notification::route('mail', [$booking->contact_email => $booking->contact_name])
                                    ->notify($notification);
                            }

                            $assignment = $booking->assignments->first();

                            if ($assignment?->driver !== null) {
                                $vehicleName = trim($assignment->vehicle->make.' '.$assignment->vehicle->model);

                                $assignment->driver->notify(new AirportTransferTeamAssignmentNotification(
                                    recipientName: $assignment->driver->name,
                                    bookingReference: $booking->reference,
                                    serviceStartsAt: $booking->service_starts_at->toIso8601String(),
                                    airportName: $booking->airport_name_snapshot,
                                    locationName: $booking->location_name_snapshot,
                                    vehicleName: $vehicleName,
                                    assigned: false,
                                    forDriver: true,
                                    viewUrl: rtrim((string) config('app.url'), '/').'/dashboard',
                                    reason: 'The pending request expired before confirmation.',
                                ));
                            }

                            $event->forceFill(['processed_at' => now()])->save();

                            return true;
                        }, 3);

                        $dispatched += $queued ? 1 : 0;
                    } catch (Throwable $exception) {
                        $failures++;
                        report($exception);
                        $this->components->error("Expiry event {$candidate->getKey()} remains retryable.");
                    }
                }
            });

        return [$dispatched, $failures];
    }

    private function customerViewUrl(AirportTransferBooking $booking): string
    {
        if ($booking->customer_id !== null) {
            return rtrim((string) config('app.url'), '/').'/portal/airport-transfers/'.rawurlencode($booking->reference);
        }

        return URL::temporarySignedRoute(
            'airport-transfer-bookings.guest.show',
            now()->addHours((int) config('airport_transfers.guest_confirmation.expiry_hours', 168)),
            ['airportTransferBooking' => $booking->reference],
        );
    }
}
