<?php

namespace App\Actions\Tours\Concerns;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

trait InteractsWithTourDomain
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

        if (! in_array($currency, config('tours.currencies', ['UGX', 'USD']), true)) {
            $this->invalid($field, 'Select a supported currency.');
        }

        return $currency;
    }

    protected function money(
        array $attributes,
        string $majorKey,
        string $minorKey,
        string $currency,
        bool $nullable = false,
    ): ?int {
        $hasMajor = array_key_exists($majorKey, $attributes) && filled($attributes[$majorKey]);
        $hasMinor = array_key_exists($minorKey, $attributes) && $attributes[$minorKey] !== null && $attributes[$minorKey] !== '';

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

    /** @return never */
    protected function invalid(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
