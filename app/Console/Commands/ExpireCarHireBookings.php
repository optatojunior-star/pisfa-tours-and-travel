<?php

namespace App\Console\Commands;

use App\Enums\CarHireBookingEventType;
use App\Enums\CarHireBookingStatus;
use App\Models\CarHireBooking;
use App\Models\CarHireBookingEvent;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExpireCarHireBookings extends Command
{
    protected $signature = 'car-hire:expire-pending-bookings';

    protected $description = 'Expire pending car-hire holds whose persisted deadline has passed';

    public function handle(AuditLogger $auditLogger): int
    {
        $now = now();
        $expired = 0;

        CarHireBooking::query()
            ->where('status', CarHireBookingStatus::Pending->value)
            ->where('hold_expires_at', '<=', $now)
            ->orderBy('id')
            ->select('id')
            ->chunkById(100, function ($bookings) use ($auditLogger, $now, &$expired): void {
                foreach ($bookings as $candidate) {
                    $changed = DB::transaction(function () use ($auditLogger, $candidate, $now): bool {
                        $booking = CarHireBooking::query()
                            ->whereKey($candidate->getKey())
                            ->lockForUpdate()
                            ->first();

                        if ($booking === null
                            || $booking->status !== CarHireBookingStatus::Pending
                            || $booking->hold_expires_at->isAfter($now)) {
                            return false;
                        }

                        $booking->forceFill(['status' => CarHireBookingStatus::Expired])->save();

                        $booking->contracts()
                            ->whereNull('voided_at')
                            ->update([
                                'voided_at' => $now,
                                'void_reason' => 'The pending booking hold expired before confirmation.',
                                'updated_at' => $now,
                            ]);

                        CarHireBookingEvent::query()->firstOrCreate(
                            [
                                'car_hire_booking_id' => $booking->getKey(),
                                'event_type' => CarHireBookingEventType::BookingExpired->value,
                            ],
                            [
                                'payload' => [
                                    'schema_version' => 1,
                                    'booking_reference' => $booking->reference,
                                    'hold_expired_at' => $booking->hold_expires_at->toIso8601String(),
                                ],
                                'processed_at' => $now,
                            ],
                        );

                        $auditLogger->record(
                            event: 'car_hire.booking_expired',
                            auditable: $booking,
                            oldValues: ['status' => CarHireBookingStatus::Pending->value],
                            newValues: ['status' => CarHireBookingStatus::Expired->value],
                            context: ['url' => 'console:car-hire:expire-pending-bookings'],
                        );

                        return true;
                    }, 3);

                    $expired += $changed ? 1 : 0;
                }
            });

        $this->components->info("Car-hire holds: {$expired} booking(s) expired.");

        return self::SUCCESS;
    }
}
