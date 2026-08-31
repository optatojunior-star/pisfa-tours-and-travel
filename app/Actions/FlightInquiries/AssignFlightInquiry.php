<?php

namespace App\Actions\FlightInquiries;

use App\Actions\FlightInquiries\Concerns\InteractsWithFlightInquiryDomain;
use App\Enums\FlightInquiryEntryType;
use App\Models\FlightInquiry;
use App\Models\User;
use App\Notifications\FlightInquiries\FlightInquiryAssignedNotification;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class AssignFlightInquiry
{
    use InteractsWithFlightInquiryDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        User $actor,
        FlightInquiry $inquiry,
        ?User $assignee,
        ?string $reason = null,
    ): FlightInquiry {
        $reason = $this->nullableString($reason);

        Validator::make(
            ['reason' => $reason],
            ['reason' => ['nullable', 'string', 'max:2000']],
        )->validate();

        $this->ensureOperationsActor($actor);

        return DB::transaction(function () use ($actor, $inquiry, $assignee, $reason): FlightInquiry {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOperationsActor($lockedActor);

            $lockedAssignee = null;

            if ($assignee !== null) {
                $lockedAssignee = User::query()->whereKey($assignee->getKey())->lockForUpdate()->first();

                if ($lockedAssignee === null) {
                    $this->invalid('assigned_to_user_id', 'Select an active operations account.');
                }

                // Re-checked under the lock: a deactivated or role-changed
                // account must not keep an inquiry queue.
                $this->ensureOperationsActor($lockedAssignee);
            }

            $lockedInquiry = FlightInquiry::query()
                ->whereKey($inquiry->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $lockedInquiry->isOpen()) {
                $this->invalid('assigned_to_user_id', 'A resolved inquiry cannot be reassigned. Reopen it first.');
            }

            $previousAssigneeId = $lockedInquiry->assigned_to_user_id;

            if ($previousAssigneeId === $lockedAssignee?->getKey()) {
                return $lockedInquiry;
            }

            $now = now();
            $lockedInquiry->forceFill([
                'assigned_to_user_id' => $lockedAssignee?->getKey(),
                'assigned_at' => $lockedAssignee === null ? null : $now,
            ])->save();

            $this->recordEntry(
                $lockedInquiry,
                FlightInquiryEntryType::Assigned,
                $lockedAssignee === null
                    ? 'Released the inquiry back to the unassigned queue.'
                    : 'Assigned the inquiry to '.$lockedAssignee->name.'.',
                $lockedActor,
                [
                    'from_user_id' => $previousAssigneeId,
                    'to_user_id' => $lockedAssignee?->getKey(),
                    'reason_present' => $reason !== null,
                ],
            );

            $this->auditLogger->record(
                event: 'flight_inquiry.assignment_changed',
                auditable: $lockedInquiry,
                oldValues: ['assigned_to_user_id' => $previousAssigneeId],
                newValues: [
                    'assigned_to_user_id' => $lockedInquiry->assigned_to_user_id,
                    'assigned_at' => $lockedInquiry->assigned_at?->toIso8601String(),
                ],
                context: ['reason_present' => $reason !== null],
                user: $lockedActor,
            );

            if ($lockedAssignee !== null) {
                $notifiable = $lockedAssignee;

                DB::afterCommit(function () use ($notifiable, $lockedInquiry, $reason): void {
                    $notifiable->notify(new FlightInquiryAssignedNotification(
                        recipientName: $notifiable->name,
                        inquiryReference: $lockedInquiry->reference,
                        routeLabel: $lockedInquiry->routeLabel(),
                        outboundOn: $lockedInquiry->outbound_on->toDateString(),
                        passengerCount: $lockedInquiry->passenger_count,
                        statusLabel: $lockedInquiry->status->label(),
                        viewUrl: route('admin.flight-inquiries.show', ['flightInquiry' => $lockedInquiry->reference]),
                        reason: $reason,
                    ));
                });
            }

            return $lockedInquiry->fresh(['customer', 'assignee', 'entries']);
        }, 3);
    }
}
