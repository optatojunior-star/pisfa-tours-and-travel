<?php

namespace App\Actions\Corporate;

use App\Enums\CorporateAccountStatus;
use App\Models\CorporateAccount;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Corporate\CorporateCreditQuery;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Creates and edits a company account.
 *
 * The commercial terms — credit limit, discount, payment days — are separated
 * from the contact details on purpose: staff keep an address up to date, but
 * only a manager decides how much the business is willing to be owed.
 */
class SaveCorporateAccount
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
        private readonly CorporateCreditQuery $credit,
    ) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes): CorporateAccount
    {
        $input = $this->validatedDetails($attributes);
        $terms = $this->validatedTerms($attributes);

        return DB::transaction(function () use ($actor, $input, $terms): CorporateAccount {
            // Creating an account sets its terms, so it takes the higher bar.
            $lockedActor = CorporateAccess::lockedTermsSetter($actor);

            $account = new CorporateAccount;
            $account->forceFill(array_merge($input, $terms, [
                'slug' => $this->uniqueSlug($input['name']),
                'status' => CorporateAccountStatus::Prospect,
                'created_by_user_id' => $lockedActor->getKey(),
            ]))->save();

            $this->auditLogger->record(
                event: 'corporate_account.created',
                auditable: $account,
                newValues: [
                    'name' => $account->name,
                    'credit_limit_minor' => $account->credit_limit_minor,
                    'currency' => $account->currency,
                    'payment_terms_days' => $account->payment_terms_days,
                    'discount_bps' => $account->discount_bps,
                ],
                user: $lockedActor,
            );

            return $account;
        }, 3);
    }

    /** Contact and billing details — day-to-day staff work. */
    public function updateDetails(User $actor, CorporateAccount $account, array $attributes): CorporateAccount
    {
        $input = $this->validatedDetails($attributes);

        return DB::transaction(function () use ($actor, $account, $input): CorporateAccount {
            $lockedActor = CorporateAccess::lockedManager($actor);

            $locked = $this->locked($account);

            $this->assertEditable($locked);

            $previous = ['name' => $locked->name, 'billing_contact_email' => $locked->billing_contact_email];

            // The slug is the console URL and stays put once the account has
            // traded, so a rename does not break links people have shared.
            $keepSlug = $locked->activated_at !== null;

            $locked->forceFill(array_merge($input, [
                'slug' => $keepSlug ? $locked->slug : $this->uniqueSlug($input['name'], $locked->getKey()),
            ]))->save();

            $this->auditLogger->record(
                event: 'corporate_account.updated',
                auditable: $locked,
                oldValues: $previous,
                newValues: ['name' => $locked->name, 'billing_contact_email' => $locked->billing_contact_email],
                user: $lockedActor,
            );

            return $locked->fresh();
        }, 3);
    }

    /**
     * The commercial terms. Manager only.
     *
     * A credit limit cannot be cut below what the company already owes: the
     * invoices are out, and pretending otherwise would show a negative headroom
     * that no screen knows how to explain.
     */
    public function updateTerms(User $actor, CorporateAccount $account, array $attributes): CorporateAccount
    {
        $terms = $this->validatedTerms($attributes);

        return DB::transaction(function () use ($actor, $account, $terms): CorporateAccount {
            $lockedActor = CorporateAccess::lockedTermsSetter($actor);

            $locked = $this->locked($account);

            $this->assertEditable($locked);

            $position = $this->credit->position($locked);

            if ($terms['currency'] !== $position['currency'] && $position['outstanding_minor'] > 0) {
                throw ValidationException::withMessages([
                    'currency' => 'This account has '
                        .Money::format($position['outstanding_minor'], $position['currency'])
                        .' outstanding. Settle it before changing the billing currency.',
                ]);
            }

            if ($terms['credit_limit_minor'] < $position['outstanding_minor']
                && $terms['currency'] === $position['currency']) {
                throw ValidationException::withMessages([
                    'credit_limit' => 'The company already owes '
                        .Money::format($position['outstanding_minor'], $position['currency'])
                        .'. The limit cannot be set below that.',
                ]);
            }

            $previous = [
                'credit_limit_minor' => $locked->credit_limit_minor,
                'payment_terms_days' => $locked->payment_terms_days,
                'discount_bps' => $locked->discount_bps,
                'currency' => $locked->currency,
            ];

            $locked->forceFill($terms)->save();

            $this->auditLogger->record(
                event: 'corporate_account.terms_changed',
                auditable: $locked,
                oldValues: $previous,
                newValues: $terms,
                user: $lockedActor,
            );

            return $locked->fresh();
        }, 3);
    }

    private function locked(CorporateAccount $account): CorporateAccount
    {
        return CorporateAccount::query()
            ->whereKey($account->getKey())
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertEditable(CorporateAccount $account): void
    {
        if (! $account->status->isEditable()) {
            throw ValidationException::withMessages([
                'status' => 'A closed account is read-only. Reopen it as a prospect to change anything.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validatedDetails(array $attributes): array
    {
        $validated = Validator::make($attributes, [
            'name' => ['required', 'string', 'min:2', 'max:200'],
            'registration_number' => ['nullable', 'string', 'max:60'],
            'tax_identification_number' => ['nullable', 'string', 'max:40'],
            'industry' => ['nullable', 'string', 'max:120'],
            'billing_contact_name' => ['required', 'string', 'min:2', 'max:180'],
            'billing_contact_email' => ['required', 'email:rfc', 'max:254'],
            'billing_contact_phone' => [
                'required',
                'string',
                'max:40',
                'regex:/\A\+?[0-9][0-9\s().-]{6,39}\z/',
            ],
            'billing_address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        return [
            'name' => trim((string) $validated['name']),
            'registration_number' => $this->nullable($validated['registration_number'] ?? null),
            'tax_identification_number' => $this->nullable($validated['tax_identification_number'] ?? null),
            'industry' => $this->nullable($validated['industry'] ?? null),
            'billing_contact_name' => trim((string) $validated['billing_contact_name']),
            'billing_contact_email' => mb_strtolower(trim((string) $validated['billing_contact_email'])),
            'billing_contact_phone' => preg_replace(
                '/[\s().-]+/',
                '',
                trim((string) $validated['billing_contact_phone']),
            ) ?? '',
            'billing_address' => $this->nullable($validated['billing_address'] ?? null),
            'notes' => $this->nullable($validated['notes'] ?? null),
            'internal_notes' => $this->nullable($validated['internal_notes'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{payment_terms_days: int, credit_limit_minor: int, currency: string, discount_bps: int}
     */
    private function validatedTerms(array $attributes): array
    {
        $validated = Validator::make($attributes, [
            'payment_terms_days' => ['required', 'integer', 'min:0', 'max:180'],
            'credit_limit' => ['required', 'string', 'max:24'],
            'currency' => ['required', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))],
            // Basis points: 500 is 5%. A discount at or above 100% would mean
            // giving the service away, which is not a discount.
            'discount_bps' => ['nullable', 'integer', 'min:0', 'max:9900'],
        ])->validate();

        $currency = strtoupper((string) $validated['currency']);

        try {
            $limit = Money::parse((string) $validated['credit_limit'], $currency);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['credit_limit' => $exception->getMessage()]);
        }

        return [
            'payment_terms_days' => (int) $validated['payment_terms_days'],
            'credit_limit_minor' => $limit,
            'currency' => $currency,
            'discount_bps' => (int) ($validated['discount_bps'] ?? 0),
        ];
    }

    /** Soft-deleted accounts are counted, so a removed URL is never reused. */
    private function uniqueSlug(string $source, ?int $ignoreId = null): string
    {
        $base = Str::slug($source) ?: 'account';
        $slug = $base;
        $suffix = 1;

        while (CorporateAccount::query()
            ->withTrashed()
            ->where('slug', $slug)
            ->when($ignoreId !== null, fn ($query) => $query->whereKeyNot($ignoreId))
            ->exists()
        ) {
            $slug = $base.'-'.(++$suffix);
        }

        return $slug;
    }

    private function nullable(mixed $value): ?string
    {
        $value = $value === null ? null : trim((string) $value);

        return $value === '' ? null : $value;
    }
}
