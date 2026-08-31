<?php

namespace App\Console\Commands;

use App\Enums\PropertyBookingEventType;
use App\Enums\PropertyBookingStatus;
use App\Models\PropertyBooking;
use App\Models\PropertyBookingEvent;
use App\Notifications\Accommodation\PropertyArrivalReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Reminds confirmed guests that their stay is coming up.
 *
 * Two passes, like the car-hire reminder: first claim a durable marker per
 * booking, then send against unprocessed markers. The unique index on
 * `(booking, event_type)` is what makes a repeated sweep — or two workers —
 * harmless, and a send that throws leaves the marker unprocessed so the next run
 * retries it rather than losing the reminder.
 *
 * Runs on a business date rather than a timestamp: the reminder is "you arrive
 * the day after tomorrow", which is a calendar fact.
 */
class SendPropertyArrivalReminders extends Command
{
    protected $signature = 'accommodation:send-arrival-reminders';

    protected $description = 'Queue idempotent arrival reminders for stays starting in the configured window';

    public function handle(): int
    {
        $timezone = (string) config('pisfa.business_timezone', 'Africa/Kampala');
        $today = CarbonImmutable::now($timezone)->startOfDay();
        $target = $today->addDays((int) config('accommodation.arrival_reminder_days', 2));

        $eligible = [
            PropertyBookingStatus::Confirmed->value,
            PropertyBookingStatus::Pending->value,
        ];

        $markersCreated = 0;

        PropertyBooking::query()
            ->whereIn('status', $eligible)
            ->whereDate('check_in_date', $target->toDateString())
            ->orderBy('id')
            ->select('id')
            ->chunkById(100, function ($bookings) use (&$markersCreated, $eligible): void {
                foreach ($bookings as $candidate) {
                    $created = DB::transaction(function () use ($candidate, $eligible): bool {
                        $booking = PropertyBooking::query()
                            ->whereKey($candidate->getKey())
                            ->lockForUpdate()
                            ->first();

                        if ($booking === null || ! in_array($booking->status->value, $eligible, true)) {
                            return false;
                        }

                        return PropertyBookingEvent::query()->firstOrCreate(
                            [
                                'property_booking_id' => $booking->getKey(),
                                'event_type' => PropertyBookingEventType::ArrivalReminderSent->value,
                            ],
                            [
                                'payload' => [
                                    'schema_version' => 1,
                                    'booking_reference' => $booking->reference,
                                    'check_in_date' => $booking->check_in_date->toDateString(),
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

        PropertyBookingEvent::query()
            ->where('event_type', PropertyBookingEventType::ArrivalReminderSent->value)
            ->unprocessed()
            ->whereHas('booking', fn (Builder $booking): Builder => $booking->whereIn('status', $eligible))
            ->orderBy('id')
            ->select('id')
            ->chunkById(100, function ($events) use (&$dispatched, &$failures, $eligible): void {
                foreach ($events as $candidate) {
                    try {
                        $queued = DB::transaction(function () use ($candidate, $eligible): bool {
                            $event = PropertyBookingEvent::query()
                                ->with(['booking.customer', 'booking.property'])
                                ->whereKey($candidate->getKey())
                                ->lockForUpdate()
                                ->first();

                            if ($event === null || $event->processed_at !== null) {
                                return false;
                            }

                            $booking = $event->booking;

                            // Re-read under the lock: the stay may have been
                            // cancelled between the two passes.
                            if ($booking === null
                                || ! in_array($booking->status->value, $eligible, true)
                                || $booking->customer === null) {
                                return false;
                            }

                            $booking->customer->notify(new PropertyArrivalReminderNotification(
                                bookingReference: $booking->reference,
                                propertyName: $booking->property_name_snapshot,
                                stay: $booking->stayLabel(),
                                checkInFrom: Str::substr((string) $booking->check_in_from_snapshot, 0, 5),
                                roomSummary: $booking->rooms.' × '.$booking->room_type_name_snapshot,
                                directions: $booking->property?->directions,
                            ));

                            $event->forceFill(['processed_at' => now()])->save();

                            return true;
                        }, 3);

                        $dispatched += $queued ? 1 : 0;
                    } catch (Throwable $exception) {
                        $failures++;
                        report($exception);
                        $this->components->error("Arrival reminder event {$candidate->getKey()} remains retryable.");
                    }
                }
            });

        $this->components->info(
            "Accommodation arrival reminders: {$markersCreated} marker(s) created; {$dispatched} notification(s) queued; {$failures} failure(s).",
        );

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
