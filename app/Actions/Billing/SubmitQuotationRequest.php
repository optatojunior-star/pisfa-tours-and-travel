<?php

namespace App\Actions\Billing;

use App\Enums\AccountStatus;
use App\Enums\QuotationRequestStatus;
use App\Enums\UserRole;
use App\Models\QuotationRequest;
use App\Models\User;
use App\Notifications\Billing\QuotationRequestReceivedNotification;
use App\Services\AuditLogger;
use App\Support\Money;
use App\Support\ServiceCatalogue;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Persists a "please quote me" enquiry from the public site.
 *
 * Guests are first-class here: `customer_id` stays null rather than pointing at
 * a placeholder account, and the guest's only credential is a random tracking
 * token that is never derived from the reference.
 */
class SubmitQuotationRequest
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function execute(?User $customer, array $attributes, string $idempotencyKey): QuotationRequest
    {
        $input = $this->validated($customer, $attributes, $idempotencyKey);

        return DB::transaction(function () use ($customer, $input): QuotationRequest {
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

            $existing = QuotationRequest::query()
                ->where('idempotency_owner_hash', $input['idempotency_owner_hash'])
                ->where('idempotency_key', $input['idempotency_key'])
                ->lockForUpdate()
                ->first();

            // A double submit is the same enquiry, not a second one.
            if ($existing !== null) {
                return $existing;
            }

            $request = new QuotationRequest;
            $request->forceFill(array_merge($input['attributes'], [
                'reference' => 'QRQ-'.Str::upper((string) Str::ulid()),
                'tracking_token' => bin2hex(random_bytes(32)),
                'customer_id' => $lockedCustomer?->getKey(),
                'status' => QuotationRequestStatus::New,
                'idempotency_owner_hash' => $input['idempotency_owner_hash'],
                'idempotency_key' => $input['idempotency_key'],
            ]))->save();

            $this->auditLogger->record(
                event: 'quotation_request.created',
                auditable: $request,
                newValues: [
                    'reference' => $request->reference,
                    'service' => $request->service,
                    'customer_id' => $request->customer_id,
                    'guest_request' => $request->isGuest(),
                ],
                user: $lockedCustomer,
            );

            DB::afterCommit(fn () => $this->notify($request));

            return $request;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{attributes: array<string, mixed>, idempotency_owner_hash: string, idempotency_key: string}
     */
    private function validated(?User $customer, array $attributes, string $idempotencyKey): array
    {
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
            'service' => ['required', Rule::in(ServiceCatalogue::keys())],
            'details' => ['required', 'string', 'min:20', 'max:5000'],
            'preferred_date' => ['nullable', 'date', 'after_or_equal:today'],
            'party_size' => ['nullable', 'integer', 'min:1', 'max:500'],
            'budget' => ['nullable', 'string', 'max:24'],
            'budget_currency' => [
                'nullable',
                'required_with:budget',
                Rule::in(config('pisfa.currency.supported', ['UGX', 'USD'])),
            ],
            'company_name' => ['nullable', 'string', 'max:180'],
            'contact_name' => ['required', 'string', 'min:2', 'max:180'],
            'contact_email' => ['required', 'email:rfc', 'max:254'],
            'contact_phone' => ['required', 'string', 'max:40', 'regex:/\A\+?[0-9][0-9\s().-]{6,39}\z/'],
            'idempotency_key' => ['required', 'uuid'],
        ])->validate();

        $budgetMinor = null;
        $budgetCurrency = null;

        if (filled($validated['budget'] ?? null)) {
            $budgetCurrency = strtoupper((string) $validated['budget_currency']);

            try {
                $budgetMinor = Money::parse((string) $validated['budget'], $budgetCurrency);
            } catch (InvalidArgumentException $exception) {
                throw ValidationException::withMessages(['budget' => $exception->getMessage()]);
            }
        }

        $email = mb_strtolower(trim($validated['contact_email']));
        $phone = preg_replace('/[\s().-]+/', '', trim($validated['contact_phone'])) ?? '';

        $owner = $customer === null
            ? 'guest|'.$email.'|'.$phone
            : 'customer|'.$customer->getKey();

        return [
            'attributes' => [
                'service' => $validated['service'],
                'details' => trim($validated['details']),
                'preferred_date' => $validated['preferred_date'] ?? null,
                'party_size' => $validated['party_size'] ?? null,
                'budget_minor' => $budgetMinor,
                'budget_currency' => $budgetCurrency,
                'company_name' => $this->nullable($validated['company_name'] ?? null),
                'contact_name' => trim($validated['contact_name']),
                'contact_email' => $email,
                'contact_phone' => $phone,
            ],
            // Keyed to the owner so one guest's replay cannot collide with
            // another's, and so a signed-in customer is deduplicated by account.
            'idempotency_owner_hash' => hash_hmac('sha256', $owner, (string) config('app.key')),
            'idempotency_key' => $validated['idempotency_key'],
        ];
    }

    private function nullable(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function notify(QuotationRequest $request): void
    {
        $notification = new QuotationRequestReceivedNotification(
            recipientName: $request->contact_name,
            reference: $request->reference,
            serviceLabel: $request->serviceLabel(),
            trackingUrl: $request->trackingUrl(),
        );

        $request->loadMissing('customer');

        if ($request->customer !== null) {
            $request->customer->notify($notification);

            return;
        }

        NotificationFacade::route('mail', [$request->contact_email => $request->contact_name])
            ->notify($notification);
    }
}
