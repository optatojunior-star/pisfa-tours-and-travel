<?php

namespace App\Actions\CarHire\Concerns;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;

trait InteractsWithCarHireDomain
{
    protected function ensureOperationsActor(User $actor): void
    {
        if ($actor->status !== AccountStatus::Active || ! $actor->hasAnyRole(
            UserRole::Staff,
            UserRole::Manager,
            UserRole::SuperAdmin,
        )) {
            throw new AuthorizationException;
        }
    }

    protected function currency(mixed $value, string $field = 'currency'): string
    {
        $currency = strtoupper(trim((string) $value));

        if (! in_array($currency, config('car_hire.currencies', ['UGX', 'USD']), true)) {
            $this->invalid($field, 'Select a supported currency.');
        }

        return $currency;
    }

    /**
     * Parse either a major-unit form value or an integer minor-unit value. When
     * both representations are supplied they must describe exactly the same
     * amount.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function money(
        array $attributes,
        string $majorKey,
        string $minorKey,
        string $currency,
        bool $nullable = false,
    ): ?int {
        $hasMajor = array_key_exists($majorKey, $attributes) && filled($attributes[$majorKey]);
        $hasMinor = array_key_exists($minorKey, $attributes)
            && $attributes[$minorKey] !== null
            && $attributes[$minorKey] !== '';

        if (! $hasMajor && ! $hasMinor) {
            if ($nullable) {
                return null;
            }

            $this->invalid($majorKey, 'Enter an amount.');
        }

        try {
            $fromMajor = $hasMajor ? Money::parse((string) $attributes[$majorKey], $currency) : null;
        } catch (InvalidArgumentException $exception) {
            $this->invalid($majorKey, $exception->getMessage());
        }

        $fromMinor = null;

        if ($hasMinor) {
            $minor = filter_var($attributes[$minorKey], FILTER_VALIDATE_INT);

            if ($minor === false || $minor < 0) {
                $this->invalid($minorKey, 'The minor-unit amount must be a non-negative integer.');
            }

            $fromMinor = (int) $minor;
        }

        if ($fromMajor !== null && $fromMinor !== null && $fromMajor !== $fromMinor) {
            $this->invalid($majorKey, 'The major and minor-unit amounts do not match.');
        }

        return $fromMajor ?? $fromMinor;
    }

    protected function utcDateTime(mixed $value, string $field): CarbonImmutable
    {
        try {
            if ($value instanceof DateTimeInterface) {
                return CarbonImmutable::instance($value)->utc();
            }

            if (! is_string($value) || trim($value) === '') {
                $this->invalid($field, 'Enter a valid date and time.');
            }

            return CarbonImmutable::parse(
                trim($value),
                (string) config('pisfa.business_timezone', 'Africa/Kampala'),
            )->utc();
        } catch (ValidationException $exception) {
            throw $exception;
        } catch (\Throwable) {
            $this->invalid($field, 'Enter a valid date and time.');
        }
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    protected function assertRentalCalculationFits(
        int $dailyRateMinor,
        int $securityDepositMinor,
        string $field,
    ): void {
        $maximumDays = max(1, (int) config('car_hire.maximum_hire_days', 90));

        if ($securityDepositMinor > PHP_INT_MAX
            || $dailyRateMinor > intdiv(PHP_INT_MAX - $securityDepositMinor, $maximumDays)) {
            $this->invalid(
                $field,
                "The rate is too large to calculate a {$maximumDays}-day hire safely.",
            );
        }
    }

    /**
     * Hash a contract independently of database JSON object-key ordering.
     * List order remains meaningful, while every associative object is sorted
     * recursively before it is encoded.
     *
     * @param  array<string, mixed>  $snapshot
     *
     * @throws JsonException
     */
    protected function contractContentHash(array $snapshot, string $terms): string
    {
        $encoded = json_encode(
            $this->canonicalContractValue($snapshot),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );

        return hash('sha256', $encoded."\n".$terms);
    }

    private function canonicalContractValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalContractValue($item);
        }

        return $value;
    }

    /** @return never */
    protected function invalid(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
