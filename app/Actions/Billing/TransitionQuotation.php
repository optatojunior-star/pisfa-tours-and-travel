<?php

namespace App\Actions\Billing;

use App\Enums\DocumentCategory;
use App\Enums\QuotationRequestStatus;
use App\Enums\QuotationStatus;
use App\Models\Quotation;
use App\Models\QuotationRequest;
use App\Models\User;
use App\Notifications\Billing\QuotationRespondedNotification;
use App\Notifications\Billing\QuotationSentNotification;
use App\Services\AuditLogger;
use App\Services\Documents\PdfRenderer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Moves a quotation through its lifecycle: send, revise, cancel, and record the
 * customer's accept or decline.
 *
 * Every path goes through the enum's transition graph, so a status can only
 * become one the graph allows.
 */
class TransitionQuotation
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly PdfRenderer $pdf,
    ) {}

    /**
     * Publishes the offer: renders the PDF, stamps `sent_at`, and mails it.
     *
     * The PDF is generated before the status flips so a rendering failure
     * leaves the quotation a draft rather than a "sent" offer with no document.
     */
    public function send(User $actor, Quotation $quotation): Quotation
    {
        // Whether this call is the one that actually published the offer. A
        // second send must not file another PDF or email the customer again.
        $published = false;

        $quotation = DB::transaction(function () use ($actor, $quotation, &$published): Quotation {
            $lockedActor = $this->lockedManager($actor);

            $locked = Quotation::query()
                ->with('items')
                ->whereKey($quotation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->status === QuotationStatus::Sent) {
                return $locked;
            }

            $this->assertTransition($locked, QuotationStatus::Sent);

            if ($locked->items->isEmpty()) {
                throw ValidationException::withMessages([
                    'items' => 'Add at least one line before sending the quotation.',
                ]);
            }

            if ($locked->total_minor < 1) {
                throw ValidationException::withMessages([
                    'items' => 'A quotation must total more than zero before it is sent.',
                ]);
            }

            $previous = $locked->status;

            $locked->forceFill([
                'status' => QuotationStatus::Sent,
                'sent_at' => now(),
                // Re-sending after a decline or expiry clears the old outcome,
                // so the record cannot show both sent and declined.
                'declined_at' => null,
                'decline_reason' => null,
                'expired_at' => null,
            ])->save();

            $this->markRequestQuoted($locked);

            $this->auditLogger->record(
                event: 'quotation.sent',
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: [
                    'status' => QuotationStatus::Sent->value,
                    'revision' => $locked->revision,
                    'total_minor' => $locked->total_minor,
                ],
                user: $lockedActor,
            );

            $published = true;

            return $locked;
        }, 3);

        if (! $published) {
            return $quotation->fresh(['items', 'customer']);
        }

        // Filed outside the status transaction: a versioned document write is
        // its own transaction, and holding the quotation row across a PDF
        // render would serialise the console for no benefit.
        $this->fileDocument($actor, $quotation);

        DB::afterCommit(fn () => $this->notifyRecipient($quotation));

        return $quotation->fresh(['items', 'customer']);
    }

    /** Pulls a sent, declined, or expired offer back for editing. */
    public function revise(User $actor, Quotation $quotation): Quotation
    {
        return DB::transaction(function () use ($actor, $quotation): Quotation {
            $lockedActor = $this->lockedManager($actor);

            $locked = Quotation::query()
                ->whereKey($quotation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertTransition($locked, QuotationStatus::Draft);

            $previous = $locked->status;

            $locked->forceFill([
                'status' => QuotationStatus::Draft,
                // A new revision number so the customer can tell one offer from
                // another when the replacement arrives.
                'revision' => $locked->revision + 1,
                'sent_at' => null,
            ])->save();

            $this->auditLogger->record(
                event: 'quotation.revised',
                auditable: $locked,
                oldValues: ['status' => $previous->value, 'revision' => $locked->revision - 1],
                newValues: ['status' => QuotationStatus::Draft->value, 'revision' => $locked->revision],
                user: $lockedActor,
            );

            return $locked->fresh(['items', 'customer']);
        }, 3);
    }

    public function cancel(User $actor, Quotation $quotation, string $reason): Quotation
    {
        $reason = trim($reason);

        Validator::make(
            ['reason' => $reason],
            ['reason' => ['required', 'string', 'min:5', 'max:255']],
        )->validate();

        return DB::transaction(function () use ($actor, $quotation, $reason): Quotation {
            $lockedActor = $this->lockedManager($actor);

            $locked = Quotation::query()
                ->whereKey($quotation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertTransition($locked, QuotationStatus::Cancelled);

            $previous = $locked->status;

            $locked->forceFill([
                'status' => QuotationStatus::Cancelled,
                'cancelled_at' => now(),
                'cancellation_reason' => $reason,
            ])->save();

            $this->auditLogger->record(
                event: 'quotation.cancelled',
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: ['status' => QuotationStatus::Cancelled->value],
                user: $lockedActor,
            );

            return $locked->fresh(['items', 'customer']);
        }, 3);
    }

    /**
     * Records the customer's decision.
     *
     * `$responder` is null for a guest acting through their token link; a signed
     * -in customer must own the quotation.
     */
    public function respond(
        ?User $responder,
        Quotation $quotation,
        bool $accepted,
        ?string $reason = null,
    ): Quotation {
        $reason = $reason === null ? null : trim($reason);

        Validator::make(
            ['reason' => $reason],
            ['reason' => ['nullable', 'string', 'min:3', 'max:500']],
        )->validate();

        $quotation = DB::transaction(function () use ($responder, $quotation, $accepted, $reason): Quotation {
            $locked = Quotation::query()
                ->whereKey($quotation->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($responder !== null && $locked->customer_id !== $responder->getKey()) {
                throw new AuthorizationException;
            }

            // A quotation owned by an account must not be settled by a guest
            // who happens to hold the token.
            if ($responder === null && $locked->customer_id !== null) {
                throw new AuthorizationException;
            }

            $next = $accepted ? QuotationStatus::Accepted : QuotationStatus::Declined;

            if ($locked->status === $next) {
                return $locked;
            }

            $this->assertTransition($locked, $next);

            // An offer past its validity date cannot be accepted at the old
            // price, even if the expiry sweep has not relabelled it yet.
            if ($locked->hasExpired()) {
                throw ValidationException::withMessages([
                    'status' => 'This quotation has expired. Ask PISFA for a fresh one.',
                ]);
            }

            $previous = $locked->status;

            $locked->forceFill([
                'status' => $next,
                'accepted_at' => $accepted ? now() : null,
                'declined_at' => $accepted ? null : now(),
                'decline_reason' => $accepted ? null : $reason,
            ])->save();

            $this->auditLogger->record(
                event: $accepted ? 'quotation.accepted' : 'quotation.declined',
                auditable: $locked,
                oldValues: ['status' => $previous->value],
                newValues: ['status' => $next->value, 'reason_present' => $reason !== null],
                user: $responder,
            );

            return $locked;
        }, 3);

        DB::afterCommit(fn () => $this->notifyTeam($quotation, $accepted, $reason));

        return $quotation->fresh(['items', 'customer']);
    }

    /** Marks a Sent quotation expired once its validity date has passed. */
    public function expire(Quotation $quotation): bool
    {
        return DB::transaction(function () use ($quotation): bool {
            $locked = Quotation::query()
                ->whereKey($quotation->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null
                || $locked->status !== QuotationStatus::Sent
                || ! $locked->hasExpired()) {
                return false;
            }

            $locked->forceFill([
                'status' => QuotationStatus::Expired,
                'expired_at' => now(),
            ])->save();

            $this->auditLogger->record(
                event: 'quotation.expired',
                auditable: $locked,
                oldValues: ['status' => QuotationStatus::Sent->value],
                newValues: [
                    'status' => QuotationStatus::Expired->value,
                    'valid_until' => $locked->valid_until?->toDateString(),
                ],
            );

            return true;
        }, 3);
    }

    private function assertTransition(Quotation $quotation, QuotationStatus $next): void
    {
        if (! $quotation->canTransitionTo($next)) {
            throw ValidationException::withMessages([
                'status' => "A {$quotation->status->label()} quotation cannot become {$next->label()}.",
            ]);
        }
    }

    private function markRequestQuoted(Quotation $quotation): void
    {
        if ($quotation->quotation_request_id === null) {
            return;
        }

        $request = QuotationRequest::query()
            ->whereKey($quotation->quotation_request_id)
            ->lockForUpdate()
            ->first();

        if ($request !== null && $request->canTransitionTo(QuotationRequestStatus::Quoted)) {
            $request->forceFill(['status' => QuotationRequestStatus::Quoted])->save();
        }
    }

    private function fileDocument(User $actor, Quotation $quotation): void
    {
        $quotation->loadMissing('items');

        $this->pdf->store(
            actor: $actor,
            owner: $quotation,
            category: DocumentCategory::Quotation,
            view: 'pdf.quotation',
            data: ['quotation' => $quotation],
            metadata: [
                'number' => $quotation->number,
                'revision' => $quotation->revision,
                'total_minor' => $quotation->total_minor,
                'currency' => $quotation->currency,
            ],
        );
    }

    private function notifyRecipient(Quotation $quotation): void
    {
        $notification = new QuotationSentNotification(
            recipientName: $quotation->contact_name,
            number: $quotation->number,
            title: $quotation->title,
            total: $quotation->formattedTotal(),
            validUntil: $quotation->valid_until?->toIso8601String(),
            revision: $quotation->revision,
            viewUrl: $quotation->viewUrl(),
        );

        $quotation->loadMissing('customer');

        if ($quotation->customer !== null) {
            $quotation->customer->notify($notification);

            return;
        }

        NotificationFacade::route('mail', [$quotation->contact_email => $quotation->contact_name])
            ->notify($notification);
    }

    private function notifyTeam(Quotation $quotation, bool $accepted, ?string $reason): void
    {
        $recipients = User::query()
            ->whereIn('id', array_filter([
                $quotation->created_by_user_id,
            ]))
            ->get();

        if ($recipients->isEmpty()) {
            return;
        }

        NotificationFacade::send($recipients, new QuotationRespondedNotification(
            number: $quotation->number,
            title: $quotation->title,
            contactName: $quotation->contact_name,
            accepted: $accepted,
            reason: $reason,
            consoleUrl: route('admin.quotations.show', $quotation->number),
        ));
    }

    private function lockedManager(User $actor): User
    {
        $locked = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

        BillingAccess::assertCanManage($locked);

        return $locked;
    }
}
