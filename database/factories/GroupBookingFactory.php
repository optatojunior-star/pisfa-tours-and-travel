<?php

namespace Database\Factories;

use App\Enums\GroupBookingStatus;
use App\Models\CorporateAccount;
use App\Models\GroupBooking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<GroupBooking> */
class GroupBookingFactory extends Factory
{
    public function definition(): array
    {
        $startsOn = CarbonImmutable::parse(now()->addDays(30)->toDateString());

        return [
            'reference' => 'GRP-'.Str::upper((string) Str::ulid()),
            'corporate_account_id' => null,
            'organiser_id' => User::factory(),
            'status' => GroupBookingStatus::Enquiry,
            'title' => 'Team retreat to Jinja',
            'service_kind' => 'corporate-travel',
            'starts_on' => $startsOn->toDateString(),
            'ends_on' => $startsOn->addDays(2)->toDateString(),
            'headcount' => 12,
            'pickup_location' => 'Kampala office',
            'destination' => 'Jinja',
            'quoted_total_minor' => null,
            'currency' => 'UGX',
        ];
    }

    public function forAccount(CorporateAccount $account): static
    {
        return $this->state(fn (): array => [
            'corporate_account_id' => $account->getKey(),
            'currency' => $account->currency,
        ]);
    }

    public function organisedBy(User $organiser): static
    {
        return $this->state(fn (): array => ['organiser_id' => $organiser->getKey()]);
    }

    public function status(GroupBookingStatus $status): static
    {
        return $this->state(fn (): array => [
            'status' => $status,
            'confirmed_at' => $status === GroupBookingStatus::Confirmed ? now()->subDay() : null,
        ]);
    }

    public function headcount(int $headcount): static
    {
        return $this->state(fn (): array => ['headcount' => $headcount]);
    }

    public function priced(int $minor, string $currency = 'UGX'): static
    {
        return $this->state(fn (): array => [
            'quoted_total_minor' => $minor,
            'currency' => $currency,
        ]);
    }
}
