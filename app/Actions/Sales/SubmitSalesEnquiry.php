<?php

namespace App\Actions\Sales;

use App\Enums\AccountStatus;
use App\Enums\SalesEnquiryStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Models\VehicleListing;
use App\Models\VehicleSalesEnquiry;
use App\Notifications\Sales\SalesEnquiryReceivedNotification;
use App\Services\AuditLogger;
use App\Support\Money;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Someone asks about a car in the showroom.
 *
 * Guests are first-class: `customer_id` stays null rather than pointing at a
 * placeholder account, and deduplication is keyed to the enquirer so one
 * person's replay cannot collide with another's.
 */
class SubmitSalesEnquiry
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function execute(
        ?User $customer,
        VehicleListing $listing,
        array $attributes,
        string $idempotencyKey,
    ): VehicleSalesEnquiry {
        $input = $this->validated($customer, $listing, $attributes, $idempotencyKey);

        return DB::transaction(function () use ($customer, $listing, $input): VehicleSalesEnquiry {
            $lockedCustomer = null;

            if ($customer !== null) {
                $lockedCustomer = User::query()
                    ->whereKey($customer->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                if ($lockedCustomer->status !== AccountStatus::Active
                    || ! $lockedCustomer->hasRole(UserRole::Customer)) {
                    throw new AuthorizationException;
                }
            }

            $lockedListing = VehicleListing::query()
                ->whereKey($listing->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            // Re-checked under the lock: a car sold a moment ago must not take
            // another enquiry.
            if (! $lockedListing->acceptsEnquiries()) {
                throw ValidationException::withMessages([
                    'listing' => 'This vehicle is no longer taking enquiries.',
                ]);
            }

            $existing = VehicleSalesEnquiry::query()
                ->where('idempotency_owner_hash', $input['idempotency_owner_hash'])
                ->where('idempotency_key', $input['idempotency_key'])
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $enquiry = new VehicleSalesEnquiry;
            $enquiry->forceFill(array_merge($input['attributes'], [
                'reference' => 'SEQ-'.Str::upper((string) Str::ulid()),
                'vehicle_listing_id' => $lockedListing->getKey(),
                'customer_id' => $lockedCustomer?->getKey(),
                'status' => SalesEnquiryStatus::New,
                'idempotency_owner_hash' => $input['idempotency_owner_hash'],
                'idempotency_key' => $input['idempotency_key'],
            ]))->save();

            $this->auditLogger->record(
                event: 'sales_enquiry.created',
                auditable: $enquiry,
                newValues: [
                    'reference' => $enquiry->reference,
                    'listing' => $lockedListing->reference,
                    'guest_enquiry' => $enquiry->isGuest(),
                    'offer_present' => $enquiry->offer_minor !== null,
                ],
                user: $lockedCustomer,
            );

            DB::afterCommit(fn () => $this->notify($enquiry, $lockedListing));

            return $enquiry;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{attributes: array<string, mixed>, idempotency_owner_hash: string, idempotency_key: string}
     */
    private function validated(
        ?User $customer,
        VehicleListing $listing,
        array $attributes,
        string $idempotencyKey,
    ): array {
        if ($customer !== null) {
            // A signed-in customer never supplies their own identity.
            $attributes = array_merge($attributes, [
                'contact_name' => $customer->name,
                'contact_email' => $customer->email,
                'contact_phone' => $customer->phone,
            ]);
        }

        $attributes['idempotency_key'] = trim($idempotencyKey);

        $validated = Validator::make($attributes, [
            'contact_name' => ['required', 'string', 'min:2', 'max:180'],
            'contact_email' => ['required', 'email:rfc', 'max:254'],
            'contact_phone' => ['required', 'string', 'max:40', 'regex:/\A\+?[0-9][0-9\s().-]{6,39}\z/'],
            'message' => ['nullable', 'string', 'max:2000'],
            'offer' => ['nullable', 'string', 'max:24'],
            'offer_currency' => [
                'nullable',
                'required_with:offer',
                Rule::in(config('pisfa.currency.supported', ['UGX', 'USD'])),
            ],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();

        $offerMinor = null;
        $offerCurrency = null;

        if (filled($validated['offer'] ?? null)) {
            $offerCurrency = strtoupper((string) $validated['offer_currency']);

            try {
                $offerMinor = Money::parse((string) $validated['offer'], $offerCurrency);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['offer' => $exception->getMessage()]);
            }

            if ($offerMinor < 1) {
                throw ValidationException::withMessages([
                    'offer' => 'An offer has to be more than nothing.',
                ]);
            }
        }

        $email = mb_strtolower(trim((string) $validated['contact_email']));
        $phone = preg_replace('/[\s().-]+/', '', trim((string) $validated['contact_phone'])) ?? '';

        // Keyed to the enquirer *and* the listing, so the same person asking
        // about two cars is two enquiries rather than a silent deduplication.
        $owner = ($customer === null ? 'guest|'.$email.'|'.$phone : 'customer|'.$customer->getKey())
            .'|listing:'.$listing->getKey();

        return [
            'attributes' => [
                'contact_name' => trim((string) $validated['contact_name']),
                'contact_email' => $email,
                'contact_phone' => $phone,
                'message' => filled($validated['message'] ?? null)
                    ? trim((string) $validated['message'])
                    : null,
                'offer_minor' => $offerMinor,
                'offer_currency' => $offerCurrency,
            ],
            'idempotency_owner_hash' => hash_hmac('sha256', $owner, (string) config('app.key')),
            'idempotency_key' => (string) $validated['idempotency_key'],
        ];
    }

    private function notify(VehicleSalesEnquiry $enquiry, VehicleListing $listing): void
    {
        $notification = new SalesEnquiryReceivedNotification(
            recipientName: $enquiry->contact_name,
            reference: $enquiry->reference,
            listingTitle: $listing->title,
            askingPrice: $listing->formattedAskingPrice(),
            listingUrl: route('showroom.show', $listing->slug),
        );

        $enquiry->loadMissing('customer');

        if ($enquiry->customer !== null) {
            $enquiry->customer->notify($notification);

            return;
        }

        NotificationFacade::route('mail', [$enquiry->contact_email => $enquiry->contact_name])
            ->notify($notification);
    }
}
