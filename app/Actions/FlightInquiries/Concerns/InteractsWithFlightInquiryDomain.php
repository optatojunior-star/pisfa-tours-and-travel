<?php

namespace App\Actions\FlightInquiries\Concerns;

use App\Enums\AccountStatus;
use App\Enums\FlightInquiryEntryType;
use App\Enums\UserRole;
use App\Models\FlightInquiry;
use App\Models\FlightInquiryEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Notifications\Notification as LaravelNotification;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use JsonException;
use RuntimeException;

trait InteractsWithFlightInquiryDomain
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

    protected function ensureInquiryCustomer(User $customer): void
    {
        if ($customer->status !== AccountStatus::Active || ! $customer->hasRole(UserRole::Customer)) {
            throw new AuthorizationException;
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
        ksort($payload, SORT_STRING);

        $encoded = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        );

        return hash_hmac('sha256', $encoded, $this->domainHashKey());
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    protected function recordEntry(
        FlightInquiry $inquiry,
        FlightInquiryEntryType $type,
        string $body,
        ?User $author = null,
        ?array $payload = null,
    ): FlightInquiryEntry {
        return FlightInquiryEntry::query()->create([
            'flight_inquiry_id' => $inquiry->getKey(),
            'author_user_id' => $author?->getKey(),
            'entry_type' => $type,
            'body' => $body,
            'payload' => $payload,
        ]);
    }

    protected function notifyInquiryRecipient(
        FlightInquiry $inquiry,
        LaravelNotification $notification,
    ): void {
        $inquiry->loadMissing('customer');

        if ($inquiry->customer !== null) {
            $inquiry->customer->notify($notification);

            return;
        }

        NotificationFacade::route('mail', [$inquiry->contact_email => $inquiry->contact_name])
            ->notify($notification);
    }

    protected function inquiryViewUrl(FlightInquiry $inquiry): string
    {
        if (! $inquiry->isGuest()) {
            return route('portal.flight-inquiries.show', ['customerFlightInquiry' => $inquiry->reference]);
        }

        return URL::temporarySignedRoute(
            'flight-inquiries.guest.show',
            now()->addHours((int) config('flight_inquiries.guest_tracking.expiry_hours', 168)),
            ['flightInquiry' => $inquiry->reference],
        );
    }

    private function domainHashKey(): string
    {
        $key = (string) config('app.key');

        if ($key === '') {
            throw new RuntimeException('APP_KEY must be configured before flight inquiries can be hashed.');
        }

        return $key;
    }

    /** @return never */
    protected function invalid(string $field, string $message): void
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
