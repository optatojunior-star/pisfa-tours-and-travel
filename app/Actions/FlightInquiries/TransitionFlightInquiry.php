<?php

namespace App\Actions\FlightInquiries;

use App\Actions\FlightInquiries\Concerns\InteractsWithFlightInquiryDomain;
use App\Enums\FlightInquiryEntryType;
use App\Enums\FlightInquiryStatus;
use App\Models\FlightInquiry;
use App\Models\User;
use App\Notifications\FlightInquiries\FlightInquiryStatusNotification;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class TransitionFlightInquiry
{
    use InteractsWithFlightInquiryDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        User $actor,
        FlightInquiry $inquiry,
        FlightInquiryStatus $nextStatus,
        ?string $reason = null,
        bool $notifyTraveller = true,
    ): FlightInquiry {
        $reason = $this->nullableString($reason);

        Validator::make(
            ['reason' => $reason],
            ['reason' => ['nullable', 'string', 'max:2000']],
        )->validate();

        $this->ensureOperationsActor($actor);

        return DB::transaction(function () use (
            $actor,
            $inquiry,
            $nextStatus,
            $reason,
            $notifyTraveller,
        ): FlightInquiry {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOperationsActor($lockedActor);

            $lockedInquiry = FlightInquiry::query()
                ->with('customer')
                ->whereKey($inquiry->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedInquiry->status === $nextStatus) {
                return $lockedInquiry;
            }

            if (! $lockedInquiry->canTransitionTo($nextStatus)) {
                $this->invalid(
                    'status',
                    "A {$lockedInquiry->status->label()} inquiry cannot move to {$nextStatus->label()}.",
                );
            }

            $isReopening = $lockedInquiry->status->isReopening($nextStatus);

            if ($isReopening) {
                $this->assertMayReopen($lockedActor, $lockedInquiry);
            }

            if (in_array($nextStatus, [
                FlightInquiryStatus::Cancelled,
                FlightInquiryStatus::Closed,
            ], true) && $reason === null) {
                $this->invalid('reason', 'Enter a reason when closing or cancelling an inquiry.');
            }

            $oldStatus = $lockedInquiry->status;
            $now = now();
            $changes = ['status' => $nextStatus];

            match ($nextStatus) {
                FlightInquiryStatus::Contacted => $changes['first_contacted_at'] = $lockedInquiry->first_contacted_at ?? $now,
                FlightInquiryStatus::Booked => $changes['booked_at'] = $now,
                FlightInquiryStatus::Closed => $changes['closed_at'] = $now,
                FlightInquiryStatus::Cancelled => $changes['cancelled_at'] = $now,
                FlightInquiryStatus::New => null,
            };

            if (in_array($nextStatus, [
                FlightInquiryStatus::Closed,
                FlightInquiryStatus::Cancelled,
            ], true)) {
                $changes['resolution_reason'] = $reason;
            }

            if ($isReopening) {
                $changes['reopened_at'] = $now;
                $changes['reopen_count'] = $lockedInquiry->reopen_count + 1;
                $changes['resolution_reason'] = null;
                $changes['closed_at'] = null;
                $changes['cancelled_at'] = null;
            }

            $lockedInquiry->forceFill($changes)->save();

            $this->recordEntry(
                $lockedInquiry,
                FlightInquiryEntryType::StatusChanged,
                $isReopening
                    ? "Reopened from {$oldStatus->label()} to {$nextStatus->label()}."
                    : "Moved from {$oldStatus->label()} to {$nextStatus->label()}.",
                $lockedActor,
                [
                    'from' => $oldStatus->value,
                    'to' => $nextStatus->value,
                    'reopened' => $isReopening,
                    'reason_present' => $reason !== null,
                ],
            );

            $this->auditLogger->record(
                event: $isReopening ? 'flight_inquiry.reopened' : 'flight_inquiry.status_changed',
                auditable: $lockedInquiry,
                oldValues: [
                    'status' => $oldStatus->value,
                    'reopen_count' => $lockedInquiry->getOriginal('reopen_count'),
                ],
                newValues: [
                    'status' => $nextStatus->value,
                    'transitioned_at' => $now->toIso8601String(),
                    'reopen_count' => $lockedInquiry->reopen_count,
                ],
                context: ['reason_present' => $reason !== null],
                user: $lockedActor,
            );

            if ($notifyTraveller) {
                DB::afterCommit(fn () => $this->notifyInquiryRecipient(
                    $lockedInquiry,
                    new FlightInquiryStatusNotification(
                        recipientName: $lockedInquiry->contact_name,
                        inquiryReference: $lockedInquiry->reference,
                        statusLabel: $nextStatus->label(),
                        routeLabel: $lockedInquiry->routeLabel(),
                        outboundOn: $lockedInquiry->outbound_on->toDateString(),
                        viewUrl: $this->inquiryViewUrl($lockedInquiry),
                        travellerReason: $reason,
                    ),
                ));
            }

            return $lockedInquiry->fresh(['customer', 'assignee', 'entries']);
        }, 3);
    }

    private function assertMayReopen(User $actor, FlightInquiry $inquiry): void
    {
        $allowedRoles = (array) config('flight_inquiries.reopen.roles', ['manager', 'super_admin']);

        if (! in_array($actor->role->value, $allowedRoles, true)) {
            throw new AuthorizationException;
        }

        $maximum = (int) config('flight_inquiries.reopen.maximum_per_inquiry', 3);

        if ($inquiry->reopen_count >= $maximum) {
            $this->invalid(
                'status',
                "This inquiry has already been reopened {$maximum} time(s). Raise a new inquiry instead.",
            );
        }
    }
}
