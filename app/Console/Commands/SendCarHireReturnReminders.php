<?php

namespace App\Console\Commands;

use App\Enums\CarHireBookingEventType;
use App\Enums\CarHireBookingStatus;
use App\Models\CarHireBooking;
use App\Models\CarHireBookingEvent;
use App\Notifications\CarHire\CarHireReturnReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendCarHireReturnReminders extends Command
{
    protected $signature = 'car-hire:send-return-reminders';

    protected $description = 'Queue idempotent vehicle-return reminders in the configured interval';

    public function handle(): int
    {
        $now = now();
        $eligibleStatuses = [
            CarHireBookingStatus::Confirmed->value,
            CarHireBookingStatus::InProgress->value,
        ];
        $windowStartsAt = $now->copy()->addMinutes(
            (int) config('car_hire.return_reminders.lead_minutes', 1440),
        );
        $windowEndsAt = $windowStartsAt->copy()->addMinutes(
            (int) config('car_hire.return_reminders.window_minutes', 15),
        );
        $markersCreated = 0;

        CarHireBooking::query()
            ->whereIn('status', $eligibleStatuses)
            ->where('return_at', '>=', $windowStartsAt)
            ->where('return_at', '<', $windowEndsAt)
            ->orderBy('id')
            ->select('id')
            ->chunkById(100, function ($bookings) use (&$markersCreated, $eligibleStatuses): void {
                foreach ($bookings as $candidate) {
                    $created = DB::transaction(function () use ($candidate, $eligibleStatuses): bool {
                        $booking = CarHireBooking::query()
                            ->whereKey($candidate->getKey())
                            ->lockForUpdate()
                            ->first();

                        if ($booking === null
                            || ! in_array($booking->status->value, $eligibleStatuses, true)
                            || ! $booking->return_at->isFuture()) {
                            return false;
                        }

                        return CarHireBookingEvent::query()->firstOrCreate(
                            [
                                'car_hire_booking_id' => $booking->getKey(),
                                'event_type' => CarHireBookingEventType::ReturnReminderSent->value,
                            ],
                            [
                                'payload' => [
                                    'schema_version' => 1,
                                    'booking_reference' => $booking->reference,
                                    'return_at' => $booking->return_at->toIso8601String(),
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

        CarHireBookingEvent::query()
            ->where('event_type', CarHireBookingEventType::ReturnReminderSent->value)
            ->unprocessed()
            ->whereHas('booking', fn (Builder $booking): Builder => $booking
                ->whereIn('status', $eligibleStatuses)
                ->where('return_at', '>', $now))
            ->orderBy('id')
            ->select('id')
            ->chunkById(100, function ($events) use (&$dispatched, &$failures, $eligibleStatuses): void {
                foreach ($events as $candidate) {
                    try {
                        $queued = DB::transaction(function () use ($candidate, $eligibleStatuses): bool {
                            $event = CarHireBookingEvent::query()
                                ->with('booking.customer')
                                ->whereKey($candidate->getKey())
                                ->lockForUpdate()
                                ->first();

                            if ($event === null || $event->processed_at !== null) {
                                return false;
                            }

                            $booking = $event->booking;

                            if ($booking === null
                                || ! in_array($booking->status->value, $eligibleStatuses, true)
                                || ! $booking->return_at->isFuture()
                                || $booking->customer === null) {
                                return false;
                            }

                            $booking->customer->notify(new CarHireReturnReminderNotification(
                                bookingReference: $booking->reference,
                                vehicleName: $booking->vehicle_name_snapshot,
                                returnAt: $booking->return_at->toIso8601String(),
                                returnLocation: $booking->return_location ?? $booking->pickup_location,
                            ));

                            $event->forceFill(['processed_at' => now()])->save();

                            return true;
                        }, 3);

                        $dispatched += $queued ? 1 : 0;
                    } catch (Throwable $exception) {
                        $failures++;
                        report($exception);
                        $this->components->error("Return reminder event {$candidate->getKey()} remains retryable.");
                    }
                }
            });

        $this->components->info(
            "Car-hire return reminders: {$markersCreated} marker(s) created; {$dispatched} notification(s) queued; {$failures} failure(s).",
        );

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
