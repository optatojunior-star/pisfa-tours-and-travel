<?php

namespace App\Actions\Leasing;

use App\Enums\LeaseApplicationStatus;
use App\Models\User;
use App\Models\VehicleLeaseApplication;
use App\Notifications\Leasing\LeaseApplicationUpdatedNotification;
use App\Services\AuditLogger;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Works an owner's offer through review.
 *
 * `Approved` is only reachable from `Inspected`, which the transition graph
 * enforces: PISFA takes on liability for a car it puts on hire, and approving a
 * vehicle nobody has looked at is a promise made about something unseen.
 */
class TransitionLeaseApplication
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function review(User $actor, VehicleLeaseApplication $application): VehicleLeaseApplication
    {
        return $this->moveTo(
            $actor,
            $application,
            LeaseApplicationStatus::UnderReview,
            'lease_application.under_review',
        );
    }

    public function arrangeInspection(
        User $actor,
        VehicleLeaseApplication $application,
        string $at,
        string $location,
    ): VehicleLeaseApplication {
        $validated = Validator::make(
            ['at' => $at, 'location' => trim($location)],
            [
                'at' => ['required', 'date', 'after:now'],
                'location' => ['required', 'string', 'min:3', 'max:255'],
            ],
            ['at.after' => 'An inspection has to be arranged for a time that has not passed.'],
        )->validate();

        return $this->moveTo(
            $actor,
            $application,
            LeaseApplicationStatus::InspectionArranged,
            'lease_application.inspection_arranged',
            [
                'inspection_at' => CarbonImmutable::parse((string) $validated['at']),
                'inspection_location' => (string) $validated['location'],
            ],
            notify: 'We have arranged to look at your vehicle.',
        );
    }

    public function recordInspection(
        User $actor,
        VehicleLeaseApplication $application,
        string $findings,
    ): VehicleLeaseApplication {
        $findings = trim($findings);

        Validator::make(
            ['findings' => $findings],
            ['findings' => ['required', 'string', 'min:10', 'max:5000']],
            ['findings.required' => 'Write down what was found. It is the basis for the terms offered.'],
        )->validate();

        return $this->moveTo(
            $actor,
            $application,
            LeaseApplicationStatus::Inspected,
            'lease_application.inspected',
            ['inspection_findings' => $findings],
        );
    }

    /** Approval is the point at which terms may be drawn up. */
    public function approve(User $actor, VehicleLeaseApplication $application): VehicleLeaseApplication
    {
        return $this->moveTo(
            $actor,
            $application,
            LeaseApplicationStatus::Approved,
            'lease_application.approved',
            ['closed_at' => now()],
            committer: true,
            notify: 'Good news — we would like to take your vehicle on. We will send you the terms.',
        );
    }

    public function decline(
        User $actor,
        VehicleLeaseApplication $application,
        string $reason,
    ): VehicleLeaseApplication {
        $reason = $this->validatedReason($reason);

        return $this->moveTo(
            $actor,
            $application,
            LeaseApplicationStatus::Declined,
            'lease_application.declined',
            ['closure_reason' => $reason, 'closed_at' => now()],
            notify: 'We are not able to take this vehicle on: '.$reason,
        );
    }

    /** The owner changed their mind; recorded from their side, not ours. */
    public function withdraw(
        User $actor,
        VehicleLeaseApplication $application,
        string $reason,
    ): VehicleLeaseApplication {
        $reason = $this->validatedReason($reason);

        return $this->moveTo(
            $actor,
            $application,
            LeaseApplicationStatus::Withdrawn,
            'lease_application.withdrawn',
            ['closure_reason' => $reason, 'closed_at' => now()],
        );
    }

    /** Puts an open application on a named person, so offers are not orphaned. */
    public function assign(
        User $actor,
        VehicleLeaseApplication $application,
        ?User $assignee,
    ): VehicleLeaseApplication {
        return DB::transaction(function () use ($actor, $application, $assignee): VehicleLeaseApplication {
            $lockedActor = LeasingAccess::lockedManager($actor);

            $lockedAssignee = null;

            if ($assignee !== null) {
                $lockedAssignee = User::query()
                    ->whereKey($assignee->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! LeasingAccess::canManage($lockedAssignee)) {
                    throw ValidationException::withMessages([
                        'assigned_to_user_id' => 'That person does not have access to the leasing console.',
                    ]);
                }
            }

            $locked = VehicleLeaseApplication::query()
                ->whereKey($application->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $previous = $locked->assigned_to_user_id;

            $locked->forceFill(['assigned_to_user_id' => $lockedAssignee?->getKey()])->save();

            $this->auditLogger->record(
                event: 'lease_application.assigned',
                auditable: $locked,
                oldValues: ['assigned_to_user_id' => $previous],
                newValues: ['assigned_to_user_id' => $lockedAssignee?->getKey()],
                user: $lockedActor,
            );

            return $locked->fresh(['owner', 'assignee']);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function moveTo(
        User $actor,
        VehicleLeaseApplication $application,
        LeaseApplicationStatus $next,
        string $event,
        array $extra = [],
        bool $committer = false,
        ?string $notify = null,
    ): VehicleLeaseApplication {
        return DB::transaction(function () use ($actor, $application, $next, $event, $extra, $committer, $notify): VehicleLeaseApplication {
            $lockedActor = $committer
                ? LeasingAccess::lockedCommitter($actor)
                : LeasingAccess::lockedManager($actor);

            $locked = VehicleLeaseApplication::query()
                ->whereKey($application->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === $next) {
                return $locked;
            }

            if (! $locked->canTransitionTo($next)) {
                throw ValidationException::withMessages([
                    'status' => "A {$locked->status->label()} application cannot become {$next->label()}.",
                ]);
            }

            $previous = $locked->status;

            $locked->forceFill(array_merge($extra, ['status' => $next]))->save();

            $this->auditLogger->record(
                event: $event,
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: ['status' => $next->value],
                user: $lockedActor,
            );

            if ($notify !== null) {
                DB::afterCommit(fn () => $this->notify($locked, $notify));
            }

            return $locked->fresh(['owner', 'assignee']);
        }, 3);
    }

    private function validatedReason(string $reason): string
    {
        $reason = trim($reason);

        Validator::make(
            ['reason' => $reason],
            ['reason' => ['required', 'string', 'min:5', 'max:255']],
        )->validate();

        return $reason;
    }

    private function notify(VehicleLeaseApplication $application, string $message): void
    {
        $notification = new LeaseApplicationUpdatedNotification(
            reference: $application->reference,
            vehicleLabel: $application->vehicleLabel(),
            statusLabel: $application->status->label(),
            message: $message,
        );

        $application->loadMissing('owner');

        if ($application->owner !== null) {
            $application->owner->notify($notification);

            return;
        }

        NotificationFacade::route('mail', [$application->contact_email => $application->contact_name])
            ->notify($notification);
    }
}
