<?php

namespace App\Actions\AirportTransfers\Concerns;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\AirportTransferBooking;
use App\Models\User;
use App\Support\Money;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\Notification as LaravelNotification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use JsonException;
use RuntimeException;

trait InteractsWithAirportTransferDomain
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

    protected function ensureBookingCustomer(User $customer): void
    {
        if ($customer->status !== AccountStatus::Active
            || ! $customer->hasRole(UserRole::Customer)
            || $customer->email_verified_at === null) {
            throw new AuthorizationException;
        }
    }

    protected function currency(mixed $value, string $field = 'currency'): string
    {
        $currency = strtoupper(trim((string) $value));

        if (! in_array($currency, config('airport_transfers.currencies', ['UGX', 'USD']), true)) {
            $this->invalid($field, 'Select a supported currency.');
        }

        return $currency;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    protected function money(
        array $attributes,
        string $majorKey,
        string $minorKey,
        string $currency,
    ): int {
        $hasMajor = array_key_exists($majorKey, $attributes) && filled($attributes[$majorKey]);
        $hasMinor = array_key_exists($minorKey, $attributes)
            && $attributes[$minorKey] !== null
            && $attributes[$minorKey] !== '';

        if (! $hasMajor && ! $hasMinor) {
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

        return (int) ($fromMajor ?? $fromMinor);
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

    protected function normalizedEmail(mixed $value): string
    {
        return mb_strtolower(trim((string) $value));
    }

    protected function normalizedPhone(mixed $value): string
    {
        $phone = preg_replace('/[\s().-]+/', '', trim((string) $value));

        return $phone ?? trim((string) $value);
    }

    protected function idempotencyOwnerHash(?User $customer, string $email, string $phone): string
    {
        $owner = $customer === null
            ? 'guest|'.$this->normalizedEmail($email).'|'.$this->normalizedPhone($phone)
            : 'customer|'.$customer->getKey();

        return hash_hmac('sha256', $owner, $this->domainHashKey());
    }

    /**
     * @param  array<string, mixed>  $payload
     *
     * @throws JsonException
     */
    protected function requestFingerprint(array $payload): string
    {
        $encoded = json_encode(
            $this->canonicalValue($payload),
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return hash_hmac('sha256', $encoded, $this->domainHashKey());
    }

    protected function notifyBookingRecipient(
        AirportTransferBooking $booking,
        LaravelNotification $notification,
    ): void {
        $booking->loadMissing('customer');

        if ($booking->customer !== null) {
            $booking->customer->notify($notification);

            return;
        }

        NotificationFacade::route('mail', $booking->contact_email)->notify($notification);
    }

    protected function bookingViewUrl(AirportTransferBooking $booking): string
    {
        if (! $booking->isGuest()) {
            return route('portal.airport-transfer-bookings.show', [
                'customerAirportTransferBooking' => $booking,
            ]);
        }

        return URL::temporarySignedRoute(
            'airport-transfer-bookings.guest.show',
            now()->addHours((int) config('airport_transfers.guest_confirmation.expiry_hours', 168)),
            ['airportTransferBooking' => $booking],
        );
    }

    private function canonicalValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonicalValue($item);
        }

        return $value;
    }

    private function domainHashKey(): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException('APP_KEY must be configured before airport-transfer requests can be hashed.');
        }

        return $key;
    }

    /** @return never */
    protected function invalid(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
