<?php

namespace App\Console\Commands;

use App\Enums\PropertyBookingEventType;
use App\Enums\PropertyBookingStatus;
use App\Models\PropertyBooking;
use App\Models\PropertyBookingEvent;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Releases rooms held by stays nobody confirmed.
 *
 * The availability query already ignores an expired hold, so this sweep is not
 * what keeps the inventory honest — it is what stops the desk's queue filling
 * with dead requests, and what gives the guest a definite answer rather than a
 * request that sits pending forever.
 */
class ExpirePropertyBookings extends Command
{
    protected $signature = 'accommodation:expire-pending-bookings';

    protected $description = 'Expire pending accommodation holds whose persisted deadline has passed';

    public function handle(AuditLogger $auditLogger): int
    {
        $now = now();
        $expired = 0;

        PropertyBooking::query()
            ->where('status', PropertyBookingStatus::Pending->value)
            ->whereNotNull('hold_expires_at')
            ->where('hold_expires_at', '<=', $now)
            ->orderBy('id')
            ->select('id')
            ->chunkById(100, function ($bookings) use ($auditLogger, $now, &$expired): void {
                foreach ($bookings as $candidate) {
                    $changed = DB::transaction(function () use ($auditLogger, $candidate, $now): bool {
                        $booking = PropertyBooking::query()
                            ->whereKey($candidate->getKey())
                            ->lockForUpdate()
                            ->first();

                        // Re-read under the lock: another worker, or the desk,
                        // may have confirmed it since the chunk was selected.
                        if ($booking === null
                            || $booking->status !== PropertyBookingStatus::Pending
                            || $booking->hold_expires_at === null
                            || $booking->hold_expires_at->isAfter($now)) {
                            return false;
                        }

                        $booking->forceFill([
                            'status' => PropertyBookingStatus::Expired,
                            'closure_reason' => 'The hold expired before the booking was confirmed.',
                            'cancelled_at' => $now,
                        ])->save();

                        PropertyBookingEvent::query()->firstOrCreate(
                            [
                                'property_booking_id' => $booking->getKey(),
                                'event_type' => PropertyBookingEventType::BookingExpired->value,
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
                            event: 'property_booking.expired',
                            auditable: $booking,
                            oldValues: ['status' => PropertyBookingStatus::Pending->value],
                            newValues: ['status' => PropertyBookingStatus::Expired->value],
                            context: ['url' => 'console:accommodation:expire-pending-bookings'],
                        );

                        return true;
                    }, 3);

                    $expired += $changed ? 1 : 0;
                }
            });

        $this->components->info("Accommodation holds: {$expired} booking(s) expired.");

        return self::SUCCESS;
    }
}
