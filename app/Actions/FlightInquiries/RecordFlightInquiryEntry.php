<?php

namespace App\Actions\FlightInquiries;

use App\Actions\FlightInquiries\Concerns\InteractsWithFlightInquiryDomain;
use App\Enums\FlightInquiryEntryType;
use App\Models\FlightInquiry;
use App\Models\FlightInquiryEntry;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class RecordFlightInquiryEntry
{
    use InteractsWithFlightInquiryDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        User $actor,
        FlightInquiry $inquiry,
        FlightInquiryEntryType $type,
        string $body,
    ): FlightInquiryEntry {
        $this->ensureOperationsActor($actor);

        $validated = Validator::make(
            ['entry_type' => $type->value, 'body' => trim($body)],
            [
                'entry_type' => [
                    'required',
                    // Status and assignment history is written by their own
                    // actions; a person may only add notes and communications.
                    Rule::in(array_map(
                        static fn (FlightInquiryEntryType $case): string => $case->value,
                        FlightInquiryEntryType::manualCases(),
                    )),
                ],
                'body' => ['required', 'string', 'min:3', 'max:5000'],
            ],
        )->validate();

        return DB::transaction(function () use ($actor, $inquiry, $type, $validated): FlightInquiryEntry {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();
            $this->ensureOperationsActor($lockedActor);

            $lockedInquiry = FlightInquiry::query()
                ->whereKey($inquiry->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $entry = $this->recordEntry(
                $lockedInquiry,
                $type,
                $validated['body'],
                $lockedActor,
                ['communication' => $type->isCommunication()],
            );

            $this->auditLogger->record(
                event: $type->isCommunication()
                    ? 'flight_inquiry.communication_recorded'
                    : 'flight_inquiry.note_recorded',
                auditable: $lockedInquiry,
                newValues: [
                    'entry_id' => $entry->getKey(),
                    'entry_type' => $type->value,
                    'body_length' => mb_strlen($validated['body']),
                ],
                user: $lockedActor,
            );

            return $entry;
        }, 3);
    }
}
