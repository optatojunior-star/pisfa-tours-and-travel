<?php

namespace App\Console\Commands;

use App\Enums\AirportTransferBookingStatus;
use App\Enums\AirportTransferEventType;
use App\Models\AirportTransferBooking;
use App\Models\AirportTransferEvent;
use App\Notifications\AirportTransfers\AirportTransferPickupReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Throwable;

class SendAirportTransferPickupReminders extends Command
{
    protected $signature = 'airport-transfers:send-pickup-reminders';

    protected $description = 'Queue idempotent airport-transfer pickup reminders in the configured interval';

    public function handle(): int
    {
        $now = now();
        $windowStartsAt = $now->copy()->addMinutes(
            (int) config('airport_transfers.pickup_reminders.lead_minutes', 1440),
        );
        $windowEndsAt = $windowStartsAt->copy()->addMinutes(
            (int) config('airport_transfers.pickup_reminders.window_minutes', 15),
        );
        $markersCreated = 0;

        AirportTransferBooking::query()
            ->where('status', AirportTransferBookingStatus::Confirmed->value)
            ->where('service_starts_at', '>=', $windowStartsAt)
            ->where('service_starts_at', '<', $windowEndsAt)
            ->orderBy('id')
            ->select('id')
            ->chunkById(100, function ($bookings) use (&$markersCreated, $windowStartsAt, $windowEndsAt): void {
                foreach ($bookings as $candidate) {
                    $created = DB::transaction(function () use ($candidate, $windowStartsAt, $windowEndsAt): bool {
                        $booking = AirportTransferBooking::query()
                            ->whereKey($candidate->getKey())
                            ->lockForUpdate()
                            ->first();

                        if ($booking === null
                            || $booking->status !== AirportTransferBookingStatus::Confirmed
                            || $booking->service_starts_at->isBefore($windowStartsAt)
                            || ! $booking->service_starts_at->isBefore($windowEndsAt)) {
                            return false;
                        }

                        return AirportTransferEvent::query()->firstOrCreate(
                            [
                                'airport_transfer_booking_id' => $booking->getKey(),
                                'event_type' => AirportTransferEventType::PickupReminder->value,
                            ],
                            [
                                'payload' => [
                                    'schema_version' => 1,
                                    'booking_reference' => $booking->reference,
                                    'service_starts_at' => $booking->service_starts_at->toIso8601String(),
                                ],
                                'processed_at' => null,
                            ],
                        )->wasRecentlyCreated;
                    }, 3);

                    $markersCreated += $created ? 1 : 0;
                }
            });

        $dispatched = 0;
        $failures = 0;

        AirportTransferEvent::query()
            ->where('event_type', AirportTransferEventType::PickupReminder->value)
            ->unprocessed()
            ->orderBy('id')
            ->select('id')
            ->chunkById(100, function ($events) use (&$dispatched, &$failures, $now): void {
                foreach ($events as $candidate) {
                    try {
                        $queued = DB::transaction(function () use ($candidate, $now): bool {
                            $event = AirportTransferEvent::query()
                                ->with('booking.customer')
                                ->whereKey($candidate->getKey())
                                ->lockForUpdate()
                                ->first();

                            if ($event === null || $event->processed_at !== null) {
                                return false;
                            }

                            $booking = $event->booking;

                            if ($booking === null
                                || $booking->status !== AirportTransferBookingStatus::Confirmed
                                || ! $booking->service_starts_at->isAfter($now)) {
                                return false;
                            }

                            $notification = new AirportTransferPickupReminderNotification(
                                recipientName: $booking->contact_name,
                                bookingReference: $booking->reference,
                                serviceStartsAt: $booking->service_starts_at->toIso8601String(),
                                airportName: $booking->airport_name_snapshot,
                                locationName: $booking->location_name_snapshot,
                                viewUrl: $this->customerViewUrl($booking),
                            );

                            if ($booking->customer !== null) {
                                $booking->customer->notify($notification);
                            } else {
                                Notification::route('mail', [$booking->contact_email => $booking->contact_name])
                                    ->notify($notification);
                            }

                            $event->forceFill(['processed_at' => now()])->save();

                            return true;
                        }, 3);

                        $dispatched += $queued ? 1 : 0;
                    } catch (Throwable $exception) {
                        $failures++;
                        report($exception);
                        $this->components->error("Pickup reminder event {$candidate->getKey()} remains retryable.");
                    }
                }
            });

        $this->components->info(
            "Airport-transfer reminders: {$markersCreated} marker(s) created; {$dispatched} notification(s) queued; {$failures} failure(s).",
        );

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
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
