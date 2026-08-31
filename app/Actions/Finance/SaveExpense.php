<?php

namespace App\Actions\Finance;

use App\Enums\ExpenseCategory;
use App\Enums\ExpenseStatus;
use App\Models\Expense;
use App\Models\User;
use App\Models\Vehicle;
use App\Services\AuditLogger;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Records money spent.
 *
 * A claim starts as a draft belonging to whoever spent the money. It stops
 * being editable the moment somebody approves it: from there the figure has
 * been signed off, and changing it would mean the approval was given to a
 * number nobody actually saw.
 */
class SaveExpense
{
    public function __construct(private readonly AuditLogger $auditLogger) {}

    /** @param array<string, mixed> $attributes */
    public function create(User $actor, array $attributes, ?Vehicle $vehicle = null): Expense
    {
        $input = $this->validated($attributes, $vehicle);

        return DB::transaction(function () use ($actor, $input, $vehicle): Expense {
            $lockedActor = FinanceAccess::lockedClaimant($actor);

            $expense = new Expense;
            $expense->forceFill(array_merge($input, [
                'reference' => 'EXP-'.Str::upper((string) Str::ulid()),
                'incurred_by_user_id' => $lockedActor->getKey(),
                'vehicle_id' => $vehicle?->getKey(),
                'status' => ExpenseStatus::Draft,
            ]))->save();

            $this->auditLogger->record(
                event: 'expense.created',
                auditable: $expense,
                newValues: [
                    'reference' => $expense->reference,
                    'category' => $expense->category->value,
                    'amount_minor' => $expense->amount_minor,
                    'currency' => $expense->currency,
                    'vehicle_id' => $expense->vehicle_id,
                    'spent_on' => $expense->spent_on->toDateString(),
                ],
                user: $lockedActor,
            );

            return $expense->fresh(['vehicle', 'incurredBy']);
        }, 3);
    }

    /** @param array<string, mixed> $attributes */
    public function update(User $actor, Expense $expense, array $attributes, ?Vehicle $vehicle = null): Expense
    {
        $input = $this->validated($attributes, $vehicle);

        return DB::transaction(function () use ($actor, $expense, $input, $vehicle): Expense {
            $lockedActor = FinanceAccess::lockedClaimant($actor);

            $locked = Expense::query()
                ->whereKey($expense->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (! $locked->status->isEditable()) {
                throw ValidationException::withMessages([
                    'status' => 'This claim has already been approved. '
                        .'Changing the figure now would mean the approval was given to a number nobody saw.',
                ]);
            }

            // A claim belongs to whoever is out of pocket. Somebody else editing
            // it would change what a colleague put their name to.
            if ((int) $locked->incurred_by_user_id !== (int) $lockedActor->getKey()
                && ! FinanceAccess::canApprove($lockedActor)) {
                throw ValidationException::withMessages([
                    'expense' => 'This claim belongs to somebody else.',
                ]);
            }

            $previous = [
                'amount_minor' => $locked->amount_minor,
                'currency' => $locked->currency,
                'category' => $locked->category->value,
            ];

            $locked->forceFill(array_merge($input, [
                'vehicle_id' => $vehicle?->getKey(),
            ]))->save();

            $this->auditLogger->record(
                event: 'expense.updated',
                auditable: $locked,
                oldValues: $previous,
                newValues: [
                    'amount_minor' => $locked->amount_minor,
                    'currency' => $locked->currency,
                    'category' => $locked->category->value,
                ],
                user: $lockedActor,
            );

            return $locked->fresh(['vehicle', 'incurredBy']);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function validated(array $attributes, ?Vehicle $vehicle): array
    {
        $validated = Validator::make($attributes, [
            'category' => ['required', Rule::enum(ExpenseCategory::class)],
            'spent_on' => ['required', 'date'],
            'amount' => ['required', 'string', 'max:24'],
            'currency' => ['required', Rule::in(config('pisfa.currency.supported', ['UGX', 'USD']))],
            'description' => ['required', 'string', 'min:3', 'max:255'],
            'supplier' => ['nullable', 'string', 'max:180'],
            'internal_notes' => ['nullable', 'string', 'max:5000'],
        ])->validate();

        $category = ExpenseCategory::from((string) $validated['category']);
        $currency = strtoupper((string) $validated['currency']);

        try {
            $minor = Money::parse((string) $validated['amount'], $currency);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['amount' => $exception->getMessage()]);
        }

        if ($minor < 1) {
            throw ValidationException::withMessages([
                'amount' => 'A claim for nothing is not a claim.',
            ]);
        }

        $spentOn = CarbonImmutable::parse((string) $validated['spent_on'])->startOfDay();

        // Business dates: "today" is today in Kampala, not in UTC.
        $today = CarbonImmutable::now((string) config('pisfa.business_timezone', 'Africa/Kampala'))->startOfDay();

        if ($spentOn->isAfter($today)) {
            throw ValidationException::withMessages([
                'spent_on' => 'Money cannot have been spent in the future.',
            ]);
        }

        // Spending charged to a vehicle has to be the kind of spending a vehicle
        // can incur — office rent belongs to no car.
        if ($vehicle !== null && ! $category->attachesToVehicle()) {
            throw ValidationException::withMessages([
                'vehicle_id' => $category->label().' is not charged to a particular vehicle.',
            ]);
        }

        return [
            'category' => $category,
            'spent_on' => $spentOn->toDateString(),
            'amount_minor' => $minor,
            'currency' => $currency,
            'description' => trim((string) $validated['description']),
            'supplier' => filled($validated['supplier'] ?? null) ? trim((string) $validated['supplier']) : null,
            'internal_notes' => filled($validated['internal_notes'] ?? null)
                ? trim((string) $validated['internal_notes'])
                : null,
        ];
    }
}
