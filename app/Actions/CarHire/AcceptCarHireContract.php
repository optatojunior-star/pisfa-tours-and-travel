<?php

namespace App\Actions\CarHire;

use App\Actions\CarHire\Concerns\InteractsWithCarHireDomain;
use App\Enums\AccountStatus;
use App\Enums\CarHireBookingStatus;
use App\Enums\UserRole;
use App\Models\CarHireBooking;
use App\Models\CarHireContract;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use JsonException;

class AcceptCarHireContract
{
    use InteractsWithCarHireDomain;

    public function __construct(private readonly AuditLogger $auditLogger) {}

    public function execute(
        User $customer,
        CarHireBooking $booking,
        CarHireContract $contract,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): CarHireContract {
        $ipAddress = $this->nullableString($ipAddress);

        if ($ipAddress !== null && filter_var($ipAddress, FILTER_VALIDATE_IP) === false) {
            $this->invalid('acceptance', 'The contract acceptance address is invalid.');
        }

        $userAgent = $this->normalizeUserAgent($userAgent);

        return DB::transaction(function () use (
            $customer,
            $booking,
            $contract,
            $ipAddress,
            $userAgent,
        ): CarHireContract {
            $lockedCustomer = User::query()->whereKey($customer->getKey())->lockForUpdate()->first();

            if ($lockedCustomer === null
                || $lockedCustomer->status !== AccountStatus::Active
                || ! $lockedCustomer->hasRole(UserRole::Customer)
                || $lockedCustomer->email_verified_at === null) {
                throw new AuthorizationException;
            }

            $lockedBooking = CarHireBooking::query()
                ->whereKey($booking->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if ($lockedBooking->customer_id !== $lockedCustomer->getKey()) {
                throw new AuthorizationException;
            }

            $currentContract = CarHireContract::query()
                ->where('car_hire_booking_id', $lockedBooking->getKey())
                ->whereNull('voided_at')
                ->orderByDesc('version')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($currentContract === null || ! $currentContract->is($contract)) {
                $this->invalid('contract', 'Accept only the current, non-voided rental contract.');
            }

            $this->assertContractIntegrity($lockedBooking, $currentContract);

            if ($currentContract->accepted_at !== null) {
                if ($currentContract->accepted_by_user_id !== $lockedCustomer->getKey()) {
                    $this->invalid('contract', 'This contract already has a different acceptance record.');
                }

                return $currentContract;
            }

            if ($lockedBooking->status !== CarHireBookingStatus::Pending
                || ! $lockedBooking->hold_expires_at->isFuture()
                || ! $lockedBooking->pickup_at->isFuture()) {
                $this->invalid('contract', 'This booking is no longer open for contract acceptance.');
            }

            $acceptedAt = now();

            $currentContract->forceFill([
                'accepted_at' => $acceptedAt,
                'accepted_by_user_id' => $lockedCustomer->getKey(),
                'acceptance_ip' => $ipAddress,
                'acceptance_user_agent' => $userAgent,
            ])->save();

            // The immutable contract content, IP address and user-agent evidence
            // remain on the authorized contract record and are not duplicated in
            // the general audit value payload.
            $this->auditLogger->record(
                event: 'car_hire_contract.accepted',
                auditable: $currentContract,
                newValues: [
                    'booking_reference' => $lockedBooking->reference,
                    'contract_number' => $currentContract->contract_number,
                    'version' => $currentContract->version,
                    'accepted_by_user_id' => $lockedCustomer->getKey(),
                    'accepted_at' => $acceptedAt->toIso8601String(),
                    'content_sha256' => $currentContract->content_sha256,
                ],
                user: $lockedCustomer,
            );

            return $currentContract->fresh(['booking.customer', 'acceptedBy']);
        }, 3);
    }

    private function assertContractIntegrity(CarHireBooking $booking, CarHireContract $contract): void
    {
        $snapshot = $contract->snapshot;

        if (! is_array($snapshot)
            || ($snapshot['booking_reference'] ?? null) !== $booking->reference
            || ($snapshot['currency'] ?? null) !== $booking->currency
            || (int) ($snapshot['total_minor'] ?? -1) !== $booking->total_minor) {
            $this->invalid('contract', 'The rental contract does not match this booking.');
        }

        try {
            $expectedHash = $this->contractContentHash($snapshot, $contract->terms_snapshot);
        } catch (JsonException) {
            $this->invalid('contract', 'The rental contract content cannot be verified.');
        }

        if (! hash_equals($contract->content_sha256, $expectedHash)) {
            $this->invalid('contract', 'The rental contract content failed its integrity check.');
        }
    }

    private function normalizeUserAgent(?string $userAgent): ?string
    {
        $userAgent = $this->nullableString($userAgent);

        if ($userAgent === null) {
            return null;
        }

        $userAgent = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $userAgent) ?? '';
        $userAgent = trim(preg_replace('/\s+/u', ' ', $userAgent) ?? '');

        return $userAgent === '' ? null : mb_substr($userAgent, 0, 500);
    }
}
