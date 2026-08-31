<?php

namespace App\Console\Commands;

use App\Enums\TourBookingEventType;
use App\Enums\TourBookingStatus;
use App\Enums\TourDepartureStatus;
use App\Models\TourBooking;
use App\Models\TourBookingEvent;
use App\Notifications\Tours\TourDepartureReminderNotification;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

class SendTourDepartureReminders extends Command
{
    protected $signature = 'tours:send-departure-reminders';

    protected $description = 'Queue idempotent reminders for confirmed tours in the configured departure window';

    public function handle(): int
    {
        $now = now();
        $leadMinutes = (int) config('tours.reminders.lead_minutes', 1440);
        $windowMinutes = (int) config('tours.reminders.window_minutes', 15);
        $windowStartsAt = $now->copy()->addMinutes($leadMinutes);
        $windowEndsAt = $windowStartsAt->copy()->addMinutes($windowMinutes);
        $markersCreated = 0;

        TourBooking::query()
            ->where('status', TourBookingStatus::Confirmed->value)
            ->whereHas('departure', function (Builder $departure) use ($windowStartsAt, $windowEndsAt): void {
                $departure
                    ->whereIn('status', TourDepartureStatus::reminderEligibleValues())
                    ->where('starts_at', '>=', $windowStartsAt)
                    ->where('starts_at', '<', $windowEndsAt);
            })
            ->orderBy('id')
            ->select('id')
            ->chunkById(100, function ($bookings) use (&$markersCreated): void {
                foreach ($bookings as $booking) {
                    $created = DB::transaction(function () use ($booking): bool {
                        $lockedBooking = TourBooking::query()
                            ->with('departure')
                            ->whereKey($booking->getKey())
                            ->lockForUpdate()
                            ->first();

                        if ($lockedBooking === null
                            || $lockedBooking->status !== TourBookingStatus::Confirmed
                            || ! $lockedBooking->departure?->status->receivesDepartureReminders()) {
                            return false;
                        }

                        return TourBookingEvent::query()->firstOrCreate(
                            [
                                'tour_booking_id' => $lockedBooking->getKey(),
                                'event_type' => TourBookingEventType::DepartureReminderSent->value,
                            ],
                            [
                                'payload' => [
                                    'schema_version' => 1,
                                    'booking_reference' => $lockedBooking->reference,
                                    'departure_starts_at' => $lockedBooking->departure_starts_at_snapshot->toIso8601String(),
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

        TourBookingEvent::query()
            ->where('event_type', TourBookingEventType::DepartureReminderSent->value)
            ->pending()
            ->whereHas('booking', function (Builder $booking) use ($now): void {
                $booking
                    ->where('status', TourBookingStatus::Confirmed->value)
                    ->whereHas('departure', function (Builder $departure) use ($now): void {
                        $departure
                            ->whereIn('status', TourDepartureStatus::reminderEligibleValues())
                            ->where('starts_at', '>', $now);
                    });
            })
            ->orderBy('id')
            ->select('id')
            ->chunkById(100, function ($events) use (&$dispatched, &$failures): void {
                foreach ($events as $event) {
                    try {
                        $queued = DB::transaction(function () use ($event): bool {
                            $lockedEvent = TourBookingEvent::query()
                                ->with(['booking.customer', 'booking.departure'])
                                ->whereKey($event->getKey())
                                ->lockForUpdate()
                                ->first();

                            if ($lockedEvent === null || $lockedEvent->processed_at !== null) {
                                return false;
                            }

                            $booking = $lockedEvent->booking;

                            if ($booking === null
                                || $booking->status !== TourBookingStatus::Confirmed
                                || ! $booking->departure?->status->receivesDepartureReminders()
                                || ! $booking->departure->starts_at->isFuture()) {
                                return false;
                            }

                            $booking->customer->notify(new TourDepartureReminderNotification(
                                bookingReference: $booking->reference,
                                tourName: $booking->package_name_snapshot,
                                departureStartsAt: $booking->departure_starts_at_snapshot->toIso8601String(),
                            ));

                            $lockedEvent->forceFill(['processed_at' => now()])->save();

                            return true;
                        }, 3);

                        $dispatched += $queued ? 1 : 0;
                    } catch (Throwable $exception) {
                        $failures++;
                        TourBookingEvent::query()
                            ->whereKey($event->getKey())
                            ->where('event_type', TourBookingEventType::DepartureReminderSent->value)
                            ->update(['processed_at' => null, 'updated_at' => now()]);
                        report($exception);
                        $this->components->error("Reminder event {$event->getKey()} could not be queued and remains retryable.");
                    }
                }
            });

        $this->components->info(
            "Tour reminders: {$markersCreated} marker(s) created; {$dispatched} notification(s) queued; {$failures} failure(s).",
        );

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
