<?php

namespace App\Actions\Sales;

use App\Enums\SalesEnquiryStatus;
use App\Models\User;
use App\Models\VehicleSalesEnquiry;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Works an enquiry through the sales pipeline.
 *
 * `Won` is deliberately absent from what this action will set. A sale is
 * recorded against the *listing*, by a manager, at the price the car actually
 * fetched — see TransitionVehicleListing::sell(), which marks the buyer's
 * enquiry won as part of that. Allowing an enquiry to be won on its own would
 * let a car be sold twice, and would put a sale in the pipeline that no revenue
 * figure knows about.
 */
class TransitionSalesEnquiry
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function advance(
        User $actor,
        VehicleSalesEnquiry $enquiry,
        SalesEnquiryStatus $next,
        ?string $note = null,
    ): VehicleSalesEnquiry {
        if ($next === SalesEnquiryStatus::Won) {
            throw ValidationException::withMessages([
                'status' => 'Record the sale against the listing. That marks this enquiry won '
                    .'and closes the others.',
            ]);
        }

        $note = $note === null ? null : trim($note);

        if ($next === SalesEnquiryStatus::Lost) {
            // Losing a lead is worth a reason: it is the only thing that
            // explains the pipeline to whoever reads it next month.
            Validator::make(
                ['reason' => $note],
                ['reason' => ['required', 'string', 'min:5', 'max:255']],
                ['reason.required' => 'Say why the enquiry was lost.'],
            )->validate();
        }

        return DB::transaction(function () use ($actor, $enquiry, $next, $note): VehicleSalesEnquiry {
            $lockedActor = SalesAccess::lockedManager($actor);

            $locked = VehicleSalesEnquiry::query()
                ->whereKey($enquiry->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === $next) {
                return $locked;
            }

            if (! $locked->canTransitionTo($next)) {
                throw ValidationException::withMessages([
                    'status' => "A {$locked->status->label()} enquiry cannot become {$next->label()}.",
                ]);
            }

            $previous = $locked->status;

            $locked->forceFill([
                'status' => $next,
                'closed_at' => $next->isOpen() ? null : now(),
                'closure_reason' => $next === SalesEnquiryStatus::Lost ? $note : null,
            ])->save();

            $this->auditLogger->record(
                event: 'sales_enquiry.status_changed',
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: ['status' => $next->value],
                user: $lockedActor,
            );

            return $locked->fresh(['listing', 'customer']);
        }, 3);
    }

    /** Puts an open enquiry on a named person, so leads are not orphaned. */
    public function assign(User $actor, VehicleSalesEnquiry $enquiry, ?User $assignee): VehicleSalesEnquiry
    {
        return DB::transaction(function () use ($actor, $enquiry, $assignee): VehicleSalesEnquiry {
            $lockedActor = SalesAccess::lockedManager($actor);

            $lockedAssignee = null;

            if ($assignee !== null) {
                $lockedAssignee = User::query()
                    ->whereKey($assignee->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                // Assigning a lead to somebody who cannot open it would hide it.
                if (! SalesAccess::canManage($lockedAssignee)) {
                    throw ValidationException::withMessages([
                        'assigned_to_user_id' => 'That person does not have access to the showroom console.',
                    ]);
                }
            }

            $locked = VehicleSalesEnquiry::query()
                ->whereKey($enquiry->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $previous = $locked->assigned_to_user_id;

            $locked->forceFill(['assigned_to_user_id' => $lockedAssignee?->getKey()])->save();

            $this->auditLogger->record(
                event: 'sales_enquiry.assigned',
                auditable: $locked,
                oldValues: ['assigned_to_user_id' => $previous],
                newValues: ['assigned_to_user_id' => $lockedAssignee?->getKey()],
                user: $lockedActor,
            );

            return $locked->fresh(['listing', 'assignee']);
        }, 3);
    }

    /** Appends to the running internal note, which is never shown to the enquirer. */
    public function note(User $actor, VehicleSalesEnquiry $enquiry, string $note): VehicleSalesEnquiry
    {
        $note = trim($note);

        Validator::make(
            ['note' => $note],
            ['note' => ['required', 'string', 'min:2', 'max:2000']],
        )->validate();

        return DB::transaction(function () use ($actor, $enquiry, $note): VehicleSalesEnquiry {
            $lockedActor = SalesAccess::lockedManager($actor);

            $locked = VehicleSalesEnquiry::query()
                ->whereKey($enquiry->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $stamp = now()->format('Y-m-d H:i').' — '.$lockedActor->name;

            $locked->forceFill([
                'internal_notes' => trim(($locked->internal_notes ?? '')."\n\n".$stamp."\n".$note),
            ])->save();

            // The note body is not audited: it is free text that may quote the
            // customer, and the audit trail is read by more people than the
            // enquiry is.
            $this->auditLogger->record(
                event: 'sales_enquiry.note_added',
                auditable: $locked,
                newValues: ['characters' => mb_strlen($note)],
                user: $lockedActor,
            );

            return $locked->fresh(['listing', 'assignee']);
        }, 3);
    }
}
