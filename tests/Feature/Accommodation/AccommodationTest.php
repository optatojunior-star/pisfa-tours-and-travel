<?php

namespace Tests\Feature\Accommodation;

use App\Actions\Accommodation\CreatePropertyBooking;
use App\Actions\Accommodation\SaveProperty;
use App\Actions\Accommodation\SaveRoomRate;
use App\Actions\Accommodation\SaveRoomType;
use App\Actions\Accommodation\TransitionProperty;
use App\Actions\Accommodation\TransitionPropertyBooking;
use App\Enums\AccountStatus;
use App\Enums\PropertyBookingStatus;
use App\Enums\PropertyStatus;
use App\Enums\PropertyType;
use App\Enums\UserRole;
use App\Http\Middleware\EnsureTwoFactorAuthenticationIsConfigured;
use App\Models\Property;
use App\Models\PropertyBooking;
use App\Models\PropertyRoomRate;
use App\Models\PropertyRoomType;
use App\Models\User;
use App\Notifications\Accommodation\PropertyArrivalReminderNotification;
use App\Notifications\Accommodation\PropertyBookingReceivedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AccommodationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();
    }

    private function user(UserRole $role, array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'role' => $role,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'phone' => '+256700'.fake()->unique()->numerify('######'),
        ], $attributes));
    }

    private function staff(): User
    {
        return $this->user(UserRole::Staff, ['two_factor_required' => false]);
    }

    /** Managers always need two-factor authentication; the column cannot opt out. */
    private function manager(): User
    {
        return $this->user(UserRole::Manager, [
            'two_factor_secret' => 'configured-for-feature-test',
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /** @return array<string, int> */
    private function verifiedSession(User $manager): array
    {
        return [
            EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_AT_SESSION_KEY => now()->timestamp,
            EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_USER_SESSION_KEY => $manager->id,
        ];
    }

    private function customer(): User
    {
        return $this->user(UserRole::Customer);
    }

    /**
     * A published property with one room type, priced, ready to book.
     *
     * @return array{0: Property, 1: PropertyRoomType, 2: PropertyRoomRate}
     */
    private function bookableProperty(int $quantity = 3, int $nightlyMinor = 250_000): array
    {
        $property = Property::factory()->published()->create();
        $roomType = PropertyRoomType::factory()->forProperty($property)->withQuantity($quantity)->create();
        $rate = PropertyRoomRate::factory()->forRoomType($roomType)->priced($nightlyMinor)->create();

        return [$property, $roomType, $rate];
    }

    /** @return array<string, mixed> */
    private function bookingPayload(array $overrides = []): array
    {
        return array_merge([
            'check_in_date' => '2026-09-10',
            'check_out_date' => '2026-09-12',
            'rooms' => 1,
            'adults' => 2,
            'children' => 0,
            'currency' => 'UGX',
            'contact_phone' => '+256700111222',
            'acknowledge_request' => '1',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function propertyPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Kazinga Channel Lodge',
            'property_type' => PropertyType::Lodge->value,
            'region' => 'Western Uganda',
            'district' => 'Kasese',
            'summary' => 'A quiet lodge twenty minutes from the Queen Elizabeth park gate.',
            'description' => str_repeat('Hot water, mosquito nets, and a proper breakfast before the morning game drive. ', 3),
            'check_in_from' => '14:00',
            'check_out_by' => '10:00',
            'cancellation_cutoff_hours' => 48,
        ], $overrides);
    }

    // ---- Availability, which is the whole domain -------------------------

    public function test_availability_is_a_count_not_an_overlap(): void
    {
        [$property, $roomType] = $this->bookableProperty(quantity: 3);

        PropertyBooking::factory()
            ->forStay($property, $roomType)
            ->nights('2026-09-10', '2026-09-12')
            ->status(PropertyBookingStatus::Confirmed)
            ->create();

        // One of three rooms is gone, so two remain — not zero.
        $this->assertSame(2, $roomType->availableRooms('2026-09-10', '2026-09-12'));
    }

    public function test_availability_is_the_busiest_night_not_the_average(): void
    {
        [$property, $roomType] = $this->bookableProperty(quantity: 3);

        // Two rooms taken on the 11th only.
        PropertyBooking::factory()->forStay($property, $roomType)
            ->nights('2026-09-11', '2026-09-12')->rooms(2)
            ->status(PropertyBookingStatus::Confirmed)->create();

        // A guest needs the same room every night, so a 10th-13th stay sees the
        // worst night, not the free 10th or 12th.
        $this->assertSame(1, $roomType->availableRooms('2026-09-10', '2026-09-13'));
    }

    public function test_same_day_turnover_is_not_a_conflict(): void
    {
        [$property, $roomType] = $this->bookableProperty(quantity: 1);

        PropertyBooking::factory()->forStay($property, $roomType)
            ->nights('2026-09-10', '2026-09-12')
            ->status(PropertyBookingStatus::Confirmed)->create();

        // The room is free again on the 12th: the departing guest does not
        // occupy the night of their checkout day.
        $this->assertSame(1, $roomType->availableRooms('2026-09-12', '2026-09-14'));
        $this->assertSame(0, $roomType->availableRooms('2026-09-11', '2026-09-13'));
    }

    public function test_a_lapsed_hold_stops_occupying_a_room_without_waiting_for_the_sweep(): void
    {
        [$property, $roomType] = $this->bookableProperty(quantity: 1);

        PropertyBooking::factory()->forStay($property, $roomType)
            ->nights('2026-09-10', '2026-09-12')
            ->holdLapsed()->create();

        $this->assertSame(1, $roomType->availableRooms('2026-09-10', '2026-09-12'));
    }

    public function test_an_unexpired_hold_does_occupy_a_room(): void
    {
        [$property, $roomType] = $this->bookableProperty(quantity: 1);

        PropertyBooking::factory()->forStay($property, $roomType)
            ->nights('2026-09-10', '2026-09-12')
            ->status(PropertyBookingStatus::Pending)->create();

        $this->assertSame(0, $roomType->availableRooms('2026-09-10', '2026-09-12'));
    }

    public function test_a_cancelled_stay_frees_its_rooms(): void
    {
        [$property, $roomType] = $this->bookableProperty(quantity: 1);

        PropertyBooking::factory()->forStay($property, $roomType)
            ->nights('2026-09-10', '2026-09-12')
            ->status(PropertyBookingStatus::Cancelled)->create();

        $this->assertSame(1, $roomType->availableRooms('2026-09-10', '2026-09-12'));
    }

    public function test_a_checked_out_stay_frees_its_rooms(): void
    {
        [$property, $roomType] = $this->bookableProperty(quantity: 1);

        PropertyBooking::factory()->forStay($property, $roomType)
            ->nights('2026-09-10', '2026-09-12')
            ->status(PropertyBookingStatus::CheckedOut)->create();

        $this->assertSame(1, $roomType->availableRooms('2026-09-10', '2026-09-12'));
    }

    public function test_a_booking_can_be_re_checked_without_counting_against_itself(): void
    {
        [$property, $roomType] = $this->bookableProperty(quantity: 1);

        $booking = PropertyBooking::factory()->forStay($property, $roomType)
            ->nights('2026-09-10', '2026-09-12')
            ->status(PropertyBookingStatus::Pending)->create();

        $this->assertSame(0, $roomType->availableRooms('2026-09-10', '2026-09-12'));
        $this->assertSame(1, $roomType->availableRooms('2026-09-10', '2026-09-12', $booking->getKey()));
    }

    public function test_a_backwards_or_empty_interval_has_no_availability(): void
    {
        [, $roomType] = $this->bookableProperty(quantity: 3);

        $this->assertSame(0, $roomType->availableRooms('2026-09-12', '2026-09-10'));
        $this->assertSame(0, $roomType->availableRooms('2026-09-12', '2026-09-12'));
    }

    // ---- Seasonal pricing --------------------------------------------------

    public function test_a_stay_that_straddles_two_seasons_has_no_rate(): void
    {
        [, $roomType] = $this->bookableProperty();
        PropertyRoomRate::query()->delete();

        PropertyRoomRate::factory()->forRoomType($roomType)
            ->season('2026-06-01', '2026-09-11')->priced(300_000)->create();
        PropertyRoomRate::factory()->forRoomType($roomType)
            ->season('2026-09-12', '2026-12-31')->priced(200_000)->create();

        // Inside one window, fine.
        $this->assertNotNull($roomType->rateFor('2026-09-09', '2026-09-11', 'UGX'));

        // Across the boundary, nothing — a season is a different price, not an
        // average, so this has to be quoted by hand.
        $this->assertNull($roomType->rateFor('2026-09-10', '2026-09-14', 'UGX'));
    }

    public function test_the_last_night_not_the_checkout_day_decides_the_rate_window(): void
    {
        [, $roomType] = $this->bookableProperty();
        PropertyRoomRate::query()->delete();

        PropertyRoomRate::factory()->forRoomType($roomType)
            ->season('2026-06-01', '2026-09-11')->priced(300_000)->create();

        // Checking out on the 12th means the last night slept is the 11th, so
        // a window ending on the 11th still covers the stay.
        $this->assertNotNull($roomType->rateFor('2026-09-10', '2026-09-12', 'UGX'));
    }

    public function test_a_season_starting_exactly_on_the_arrival_date_is_found(): void
    {
        [, $roomType] = $this->bookableProperty();
        PropertyRoomRate::query()->delete();

        PropertyRoomRate::factory()->forRoomType($roomType)
            ->season('2026-09-10', '2026-12-31')->priced(300_000)->create();

        // The boundary: a date column carries a midnight time component, so a
        // plain string comparison reads this season as starting after the 10th.
        $this->assertNotNull($roomType->rateFor('2026-09-10', '2026-09-12', 'UGX'));
    }

    public function test_a_season_ending_exactly_where_another_starts_is_an_overlap(): void
    {
        $manager = $this->manager();
        [, $roomType] = $this->bookableProperty();
        PropertyRoomRate::query()->delete();

        PropertyRoomRate::factory()->forRoomType($roomType)
            ->season('2026-09-10', '2026-09-30')->create();

        $this->expectException(ValidationException::class);

        // Both would cover the 10th.
        app(SaveRoomRate::class)->create($manager, $roomType, [
            'currency' => 'UGX',
            'nightly_rate' => '300000',
            'effective_from' => '2026-06-01',
            'effective_until' => '2026-09-10',
            'minimum_nights' => 1,
        ]);
    }

    public function test_two_active_rates_may_not_cover_the_same_night(): void
    {
        $manager = $this->manager();
        [, $roomType] = $this->bookableProperty();

        $this->expectException(ValidationException::class);

        app(SaveRoomRate::class)->create($manager, $roomType, [
            'currency' => 'UGX',
            'nightly_rate' => '300000',
            'effective_from' => '2026-09-01',
            'effective_until' => '2026-09-30',
            'minimum_nights' => 1,
        ]);
    }

    public function test_a_retired_rate_frees_the_dates_for_a_new_one(): void
    {
        $manager = $this->manager();
        [, $roomType, $rate] = $this->bookableProperty();

        app(SaveRoomRate::class)->deactivate($manager, $rate);

        $replacement = app(SaveRoomRate::class)->create($manager, $roomType, [
            'currency' => 'UGX',
            'nightly_rate' => '300000',
            'effective_from' => '2026-09-01',
            'effective_until' => '2026-09-30',
            'minimum_nights' => 1,
        ]);

        $this->assertSame(300_000, $replacement->nightly_rate_minor);
        $this->assertFalse($rate->fresh()->is_active);
    }

    public function test_staff_cannot_price_a_room(): void
    {
        [, $roomType] = $this->bookableProperty();

        $this->expectException(AuthorizationException::class);

        app(SaveRoomRate::class)->create($this->staff(), $roomType, [
            'currency' => 'UGX',
            'nightly_rate' => '300000',
            'effective_from' => '2027-01-01',
            'minimum_nights' => 1,
        ]);
    }

    // ---- Booking -----------------------------------------------------------

    public function test_a_customer_can_book_a_stay(): void
    {
        $customer = $this->customer();
        [$property, $roomType] = $this->bookableProperty();

        $booking = app(CreatePropertyBooking::class)->execute(
            $customer,
            $property,
            $roomType,
            $this->bookingPayload(),
            (string) Str::uuid(),
        );

        $this->assertSame(PropertyBookingStatus::Pending, $booking->status);
        $this->assertSame(2, $booking->nights);
        // UGX has no minor unit: 250,000 a night for two nights, one room.
        $this->assertSame(500_000, $booking->total_minor);
        $this->assertSame($property->name, $booking->property_name_snapshot);
        $this->assertDatabaseHas('audit_logs', ['event' => 'property_booking.created']);
    }

    public function test_the_total_multiplies_by_rooms_as_well_as_nights(): void
    {
        $customer = $this->customer();
        [$property, $roomType] = $this->bookableProperty(quantity: 5);

        $booking = app(CreatePropertyBooking::class)->execute(
            $customer,
            $property,
            $roomType,
            $this->bookingPayload(['rooms' => 3, 'adults' => 6]),
            (string) Str::uuid(),
        );

        $this->assertSame(250_000 * 2 * 3, $booking->total_minor);
    }

    public function test_the_last_room_can_be_booked_but_not_the_one_after(): void
    {
        [$property, $roomType] = $this->bookableProperty(quantity: 1);
        $action = app(CreatePropertyBooking::class);

        $action->execute($this->customer(), $property, $roomType, $this->bookingPayload(), (string) Str::uuid());

        $this->expectException(ValidationException::class);

        $action->execute($this->customer(), $property, $roomType, $this->bookingPayload(), (string) Str::uuid());
    }

    public function test_booking_more_rooms_than_remain_is_refused(): void
    {
        [$property, $roomType] = $this->bookableProperty(quantity: 2);
        $action = app(CreatePropertyBooking::class);

        $action->execute($this->customer(), $property, $roomType, $this->bookingPayload(), (string) Str::uuid());

        $this->expectException(ValidationException::class);

        $action->execute(
            $this->customer(),
            $property,
            $roomType,
            $this->bookingPayload(['rooms' => 2, 'adults' => 4]),
            (string) Str::uuid(),
        );
    }

    public function test_an_unpublished_property_cannot_be_booked(): void
    {
        $property = Property::factory()->create();
        $roomType = PropertyRoomType::factory()->forProperty($property)->create();
        PropertyRoomRate::factory()->forRoomType($roomType)->create();

        $this->expectException(ValidationException::class);

        app(CreatePropertyBooking::class)->execute(
            $this->customer(),
            $property,
            $roomType,
            $this->bookingPayload(),
            (string) Str::uuid(),
        );
    }

    public function test_a_room_from_another_property_is_refused(): void
    {
        [$property] = $this->bookableProperty();
        [, $otherRoomType] = $this->bookableProperty();

        $this->expectException(ValidationException::class);

        app(CreatePropertyBooking::class)->execute(
            $this->customer(),
            $property,
            $otherRoomType,
            $this->bookingPayload(),
            (string) Str::uuid(),
        );
    }

    public function test_too_many_guests_for_the_rooms_is_refused(): void
    {
        $property = Property::factory()->published()->create();
        $roomType = PropertyRoomType::factory()->forProperty($property)->sleeping(adults: 2)->create();
        PropertyRoomRate::factory()->forRoomType($roomType)->create();

        $this->expectException(ValidationException::class);

        app(CreatePropertyBooking::class)->execute(
            $this->customer(),
            $property,
            $roomType,
            $this->bookingPayload(['adults' => 3]),
            (string) Str::uuid(),
        );
    }

    public function test_occupancy_is_counted_across_rooms_not_per_room(): void
    {
        $property = Property::factory()->published()->create();
        $roomType = PropertyRoomType::factory()->forProperty($property)
            ->sleeping(adults: 2)->withQuantity(4)->create();
        PropertyRoomRate::factory()->forRoomType($roomType)->create();

        // Five adults do not fit in two doubles, but four do — and a family
        // should not have to split that into two bookings.
        $booking = app(CreatePropertyBooking::class)->execute(
            $this->customer(),
            $property,
            $roomType,
            $this->bookingPayload(['rooms' => 2, 'adults' => 4]),
            (string) Str::uuid(),
        );

        $this->assertSame(4, $booking->adults);
    }

    public function test_a_minimum_stay_is_enforced(): void
    {
        $property = Property::factory()->published()->create();
        $roomType = PropertyRoomType::factory()->forProperty($property)->create();
        PropertyRoomRate::factory()->forRoomType($roomType)->minimumNights(3)->create();

        $this->expectException(ValidationException::class);

        app(CreatePropertyBooking::class)->execute(
            $this->customer(),
            $property,
            $roomType,
            $this->bookingPayload(),
            (string) Str::uuid(),
        );
    }

    public function test_arrival_in_the_past_is_refused(): void
    {
        [$property, $roomType] = $this->bookableProperty();

        $this->expectException(ValidationException::class);

        app(CreatePropertyBooking::class)->execute(
            $this->customer(),
            $property,
            $roomType,
            $this->bookingPayload(['check_in_date' => '2026-08-01', 'check_out_date' => '2026-08-03']),
            (string) Str::uuid(),
        );
    }

    public function test_an_unpriced_currency_is_refused(): void
    {
        [$property, $roomType] = $this->bookableProperty();

        $this->expectException(ValidationException::class);

        app(CreatePropertyBooking::class)->execute(
            $this->customer(),
            $property,
            $roomType,
            $this->bookingPayload(['currency' => 'USD']),
            (string) Str::uuid(),
        );
    }

    public function test_replaying_the_same_key_returns_the_same_booking(): void
    {
        $customer = $this->customer();
        [$property, $roomType] = $this->bookableProperty();
        $key = (string) Str::uuid();
        $action = app(CreatePropertyBooking::class);

        $first = $action->execute($customer, $property, $roomType, $this->bookingPayload(), $key);
        $second = $action->execute($customer, $property, $roomType, $this->bookingPayload(), $key);

        $this->assertTrue($first->is($second));
        $this->assertSame(1, PropertyBooking::query()->count());
    }

    public function test_a_replayed_key_describing_a_different_stay_is_refused(): void
    {
        $customer = $this->customer();
        [$property, $roomType] = $this->bookableProperty();
        $key = (string) Str::uuid();
        $action = app(CreatePropertyBooking::class);

        $action->execute($customer, $property, $roomType, $this->bookingPayload(), $key);

        $this->expectException(ValidationException::class);

        $action->execute(
            $customer,
            $property,
            $roomType,
            $this->bookingPayload(['check_out_date' => '2026-09-14']),
            $key,
        );
    }

    public function test_the_database_refuses_a_duplicate_customer_and_key_pair(): void
    {
        $customer = $this->customer();
        [$property, $roomType] = $this->bookableProperty();
        $key = (string) Str::uuid();

        PropertyBooking::factory()->forStay($property, $roomType)->forCustomer($customer)
            ->create(['idempotency_key' => $key]);

        $this->expectException(QueryException::class);

        PropertyBooking::factory()->forStay($property, $roomType)->forCustomer($customer)
            ->create(['idempotency_key' => $key]);
    }

    public function test_the_hold_never_outlives_the_arrival_it_holds(): void
    {
        config(['accommodation.pending_hold_minutes' => 20160]);

        [$property, $roomType] = $this->bookableProperty();

        $booking = app(CreatePropertyBooking::class)->execute(
            $this->customer(),
            $property,
            $roomType,
            $this->bookingPayload(['check_in_date' => '2026-08-22', 'check_out_date' => '2026-08-24']),
            (string) Str::uuid(),
        );

        $this->assertNotNull($booking->hold_expires_at);
        $this->assertTrue($booking->hold_expires_at->isBefore(now()->addDays(3)));
    }

    public function test_a_booking_notifies_the_customer(): void
    {
        $customer = $this->customer();
        [$property, $roomType] = $this->bookableProperty();

        app(CreatePropertyBooking::class)->execute(
            $customer,
            $property,
            $roomType,
            $this->bookingPayload(),
            (string) Str::uuid(),
        );

        Notification::assertSentTo($customer, PropertyBookingReceivedNotification::class);
    }

    // ---- The stay's life ----------------------------------------------------

    public function test_every_booking_status_is_reachable(): void
    {
        $manager = $this->manager();
        [$property, $roomType] = $this->bookableProperty(quantity: 4);
        $transition = app(TransitionPropertyBooking::class);
        $create = app(CreatePropertyBooking::class);

        $pending = $create->execute($this->customer(), $property, $roomType, $this->bookingPayload(), (string) Str::uuid());
        $this->assertSame(PropertyBookingStatus::Pending, $pending->status);

        $confirmed = $transition->confirm($manager, $pending);
        $this->assertSame(PropertyBookingStatus::Confirmed, $confirmed->status);
        $this->assertNull($confirmed->hold_expires_at);

        $checkedIn = $transition->checkIn($manager, $confirmed);
        $this->assertSame(PropertyBookingStatus::CheckedIn, $checkedIn->status);

        $checkedOut = $transition->checkOut($manager, $checkedIn);
        $this->assertSame(PropertyBookingStatus::CheckedOut, $checkedOut->status);

        $declined = $transition->decline(
            $manager,
            $create->execute($this->customer(), $property, $roomType, $this->bookingPayload(), (string) Str::uuid()),
            'The lodge is closed for maintenance that week.',
        );
        $this->assertSame(PropertyBookingStatus::Declined, $declined->status);

        $cancelled = $transition->cancel(
            $manager,
            $create->execute($this->customer(), $property, $roomType, $this->bookingPayload(), (string) Str::uuid()),
            'The guest asked us to cancel.',
        );
        $this->assertSame(PropertyBookingStatus::Cancelled, $cancelled->status);

        $lapsed = PropertyBooking::factory()->forStay($property, $roomType)
            ->forCustomer($this->customer())->holdLapsed()->create();
        $this->artisan('accommodation:expire-pending-bookings')->assertSuccessful();
        $this->assertSame(PropertyBookingStatus::Expired, $lapsed->fresh()->status);
    }

    public function test_confirming_re_checks_availability(): void
    {
        $manager = $this->manager();
        [$property, $roomType] = $this->bookableProperty(quantity: 1);

        $pending = PropertyBooking::factory()->forStay($property, $roomType)
            ->forCustomer($this->customer())
            ->nights('2026-09-10', '2026-09-12')
            ->holdLapsed()->create();

        // While its hold was lapsed somebody else took the only room.
        PropertyBooking::factory()->forStay($property, $roomType)
            ->forCustomer($this->customer())
            ->nights('2026-09-10', '2026-09-12')
            ->status(PropertyBookingStatus::Confirmed)->create();

        $this->expectException(ValidationException::class);

        app(TransitionPropertyBooking::class)->confirm($manager, $pending);
    }

    public function test_confirming_does_not_count_the_booking_against_itself(): void
    {
        $manager = $this->manager();
        [$property, $roomType] = $this->bookableProperty(quantity: 1);

        $pending = PropertyBooking::factory()->forStay($property, $roomType)
            ->forCustomer($this->customer())
            ->nights('2026-09-10', '2026-09-12')
            ->status(PropertyBookingStatus::Pending)->create();

        $confirmed = app(TransitionPropertyBooking::class)->confirm($manager, $pending);

        $this->assertSame(PropertyBookingStatus::Confirmed, $confirmed->status);
    }

    public function test_a_guest_may_cancel_before_the_cutoff_but_not_after(): void
    {
        $customer = $this->customer();
        $property = Property::factory()->published()->cancellableUpTo(48)->create();
        $roomType = PropertyRoomType::factory()->forProperty($property)->create();
        PropertyRoomRate::factory()->forRoomType($roomType)->create();
        $action = app(TransitionPropertyBooking::class);

        $inTime = PropertyBooking::factory()->forStay($property, $roomType)->forCustomer($customer)
            ->status(PropertyBookingStatus::Confirmed)
            ->create(['cancellation_cutoff_at' => now()->addDay()]);

        $cancelled = $action->cancel($customer, $inTime, 'Plans changed.', byCustomer: true);
        $this->assertSame(PropertyBookingStatus::Cancelled, $cancelled->status);

        $tooLate = PropertyBooking::factory()->forStay($property, $roomType)->forCustomer($customer)
            ->status(PropertyBookingStatus::Confirmed)
            ->create(['cancellation_cutoff_at' => now()->subHour()]);

        $this->expectException(ValidationException::class);

        $action->cancel($customer, $tooLate, 'Plans changed.', byCustomer: true);
    }

    public function test_a_guest_cannot_cancel_somebody_elses_stay(): void
    {
        [$property, $roomType] = $this->bookableProperty();

        $booking = PropertyBooking::factory()->forStay($property, $roomType)
            ->forCustomer($this->customer())
            ->status(PropertyBookingStatus::Confirmed)
            ->create(['cancellation_cutoff_at' => now()->addDay()]);

        $this->expectException(ValidationException::class);

        app(TransitionPropertyBooking::class)
            ->cancel($this->customer(), $booking, 'Not mine.', byCustomer: true);
    }

    public function test_staff_may_cancel_after_the_cutoff(): void
    {
        [$property, $roomType] = $this->bookableProperty();

        $booking = PropertyBooking::factory()->forStay($property, $roomType)
            ->forCustomer($this->customer())
            ->status(PropertyBookingStatus::Confirmed)
            ->create(['cancellation_cutoff_at' => now()->subDay()]);

        $cancelled = app(TransitionPropertyBooking::class)
            ->cancel($this->staff(), $booking, 'The lodge flooded.');

        $this->assertSame(PropertyBookingStatus::Cancelled, $cancelled->status);
    }

    public function test_a_finished_stay_cannot_be_reopened(): void
    {
        [$property, $roomType] = $this->bookableProperty();

        $booking = PropertyBooking::factory()->forStay($property, $roomType)
            ->forCustomer($this->customer())
            ->status(PropertyBookingStatus::CheckedOut)->create();

        $this->assertSame([], $booking->status->allowedTransitions());

        $this->expectException(ValidationException::class);

        app(TransitionPropertyBooking::class)->cancel($this->staff(), $booking, 'Changed my mind.');
    }

    public function test_an_arrival_reminder_is_sent_once_however_often_the_sweep_runs(): void
    {
        $customer = $this->customer();
        [$property, $roomType] = $this->bookableProperty();

        $days = (int) config('accommodation.arrival_reminder_days');
        $arrival = now(config('pisfa.business_timezone'))->startOfDay()->addDays($days);

        PropertyBooking::factory()->forStay($property, $roomType)->forCustomer($customer)
            ->nights($arrival->toDateString(), $arrival->copy()->addDays(2)->toDateString())
            ->status(PropertyBookingStatus::Confirmed)->create();

        $this->artisan('accommodation:send-arrival-reminders')->assertSuccessful();
        $this->artisan('accommodation:send-arrival-reminders')->assertSuccessful();

        Notification::assertSentToTimes($customer, PropertyArrivalReminderNotification::class, 1);
    }

    public function test_a_cancelled_stay_gets_no_arrival_reminder(): void
    {
        $customer = $this->customer();
        [$property, $roomType] = $this->bookableProperty();

        $days = (int) config('accommodation.arrival_reminder_days');
        $arrival = now(config('pisfa.business_timezone'))->startOfDay()->addDays($days);

        PropertyBooking::factory()->forStay($property, $roomType)->forCustomer($customer)
            ->nights($arrival->toDateString(), $arrival->copy()->addDays(2)->toDateString())
            ->status(PropertyBookingStatus::Cancelled)->create();

        $this->artisan('accommodation:send-arrival-reminders')->assertSuccessful();

        Notification::assertNotSentTo($customer, PropertyArrivalReminderNotification::class);
    }

    public function test_the_expiry_sweep_is_idempotent(): void
    {
        [$property, $roomType] = $this->bookableProperty();

        PropertyBooking::factory()->forStay($property, $roomType)
            ->forCustomer($this->customer())->holdLapsed()->create();

        $this->artisan('accommodation:expire-pending-bookings')->assertSuccessful();
        $this->artisan('accommodation:expire-pending-bookings')->assertSuccessful();

        $this->assertSame(1, PropertyBooking::query()
            ->where('status', PropertyBookingStatus::Expired->value)
            ->count());
    }

    // ---- Property lifecycle --------------------------------------------------

    public function test_a_property_cannot_be_published_without_a_priced_room(): void
    {
        $manager = $this->manager();
        $property = Property::factory()->create();

        $this->expectException(ValidationException::class);

        app(TransitionProperty::class)->publish($manager, $property);
    }

    public function test_a_property_with_a_priced_room_publishes(): void
    {
        $manager = $this->manager();
        $property = Property::factory()->create();
        $roomType = PropertyRoomType::factory()->forProperty($property)->create();
        PropertyRoomRate::factory()->forRoomType($roomType)->create();

        $published = app(TransitionProperty::class)->publish($manager, $property);

        $this->assertSame(PropertyStatus::Published, $published->status);
        $this->assertNotNull($published->published_at);
    }

    public function test_unpublishing_and_republishing_keeps_the_first_publication_date(): void
    {
        $manager = $this->manager();
        $property = Property::factory()->create();
        $roomType = PropertyRoomType::factory()->forProperty($property)->create();
        PropertyRoomRate::factory()->forRoomType($roomType)->create();
        $action = app(TransitionProperty::class);

        $first = $action->publish($manager, $property);
        $originalDate = $first->published_at;

        $this->travel(2)->days();

        $action->unpublish($manager, $first);
        $again = $action->publish($manager, $first->fresh());

        $this->assertTrue($originalDate->equalTo($again->published_at));
    }

    public function test_a_property_with_unfinished_stays_cannot_be_archived(): void
    {
        $manager = $this->manager();
        [$property, $roomType] = $this->bookableProperty();

        PropertyBooking::factory()->forStay($property, $roomType)
            ->forCustomer($this->customer())
            ->nights('2026-09-10', '2026-09-12')
            ->status(PropertyBookingStatus::Confirmed)->create();

        $this->expectException(ValidationException::class);

        app(TransitionProperty::class)->archive($manager, $property);
    }

    public function test_a_room_type_quantity_cannot_drop_below_what_is_committed(): void
    {
        [$property, $roomType] = $this->bookableProperty(quantity: 3);

        PropertyBooking::factory()->forStay($property, $roomType)
            ->forCustomer($this->customer())
            ->nights('2026-09-10', '2026-09-12')->rooms(2)
            ->status(PropertyBookingStatus::Confirmed)->create();

        $this->expectException(ValidationException::class);

        app(SaveRoomType::class)->update($this->staff(), $roomType, [
            'name' => $roomType->name,
            'quantity' => 1,
            'max_adults' => 2,
            'max_children' => 2,
            'is_active' => true,
        ]);
    }

    public function test_a_published_slug_is_frozen(): void
    {
        $manager = $this->manager();
        $property = Property::factory()->published()->create();
        $original = $property->slug;

        $updated = app(SaveProperty::class)->update(
            $manager,
            $property,
            $this->propertyPayload(['name' => 'A Completely Different Name']),
        );

        $this->assertSame($original, $updated->slug);
        $this->assertSame('A Completely Different Name', $updated->name);
    }

    public function test_an_archived_property_is_read_only(): void
    {
        $property = Property::factory()->archived()->create();

        $this->expectException(ValidationException::class);

        app(SaveProperty::class)->update($this->staff(), $property, $this->propertyPayload());
    }

    public function test_internal_notes_are_never_serialised(): void
    {
        $property = Property::factory()->published()->create(['internal_notes' => 'Owner is difficult']);
        $booking = PropertyBooking::factory()->create(['internal_notes' => 'Complained last time']);

        $this->assertArrayNotHasKey('internal_notes', $property->toArray());
        $this->assertArrayNotHasKey('internal_notes', $booking->toArray());
        $this->assertArrayNotHasKey('idempotency_key', $booking->toArray());
        $this->assertArrayNotHasKey('request_fingerprint', $booking->toArray());
    }

    // ---- Public surfaces ------------------------------------------------------

    public function test_the_catalogue_shows_only_published_properties(): void
    {
        $live = Property::factory()->published()->create(['name' => 'Live Lodge']);
        Property::factory()->create(['name' => 'Draft Lodge']);
        Property::factory()->scheduled()->create(['name' => 'Scheduled Lodge']);
        Property::factory()->archived()->create(['name' => 'Archived Lodge']);

        $this->get(route('accommodation.index'))
            ->assertOk()
            ->assertSee($live->name)
            ->assertDontSee('Draft Lodge')
            ->assertDontSee('Scheduled Lodge')
            ->assertDontSee('Archived Lodge');
    }

    public function test_a_property_published_one_second_in_the_future_is_unreachable(): void
    {
        $property = Property::factory()->create([
            'status' => PropertyStatus::Published,
            'published_at' => now()->addSecond(),
        ]);

        $this->get(route('accommodation.show', $property->slug))->assertNotFound();
    }

    public function test_a_draft_property_is_unreachable_by_slug(): void
    {
        $property = Property::factory()->create();

        $this->get(route('accommodation.show', $property->slug))->assertNotFound();
    }

    public function test_the_property_page_reports_availability_for_requested_dates(): void
    {
        [$property, $roomType] = $this->bookableProperty(quantity: 2);

        PropertyBooking::factory()->forStay($property, $roomType)
            ->forCustomer($this->customer())
            ->nights('2026-09-10', '2026-09-12')
            ->status(PropertyBookingStatus::Confirmed)->create();

        $this->getJson(route('accommodation.availability', [
            'property' => $property->slug,
            'roomType' => $roomType->getKey(),
            'check_in_date' => '2026-09-10',
            'check_out_date' => '2026-09-12',
        ]))->assertOk()->assertJson(['available' => 1]);
    }

    public function test_availability_without_dates_says_so_rather_than_guessing(): void
    {
        [$property, $roomType] = $this->bookableProperty();

        $this->getJson(route('accommodation.availability', [
            'property' => $property->slug,
            'roomType' => $roomType->getKey(),
        ]))->assertOk()->assertJson(['available' => null]);
    }

    public function test_a_guest_cannot_book(): void
    {
        [$property, $roomType] = $this->bookableProperty();

        $this->post(route('accommodation.book', $property->slug), $this->bookingPayload([
            'property_room_type_id' => $roomType->getKey(),
            'idempotency_key' => (string) Str::uuid(),
        ]))->assertRedirect(route('login'));
    }

    public function test_a_customer_books_through_the_form(): void
    {
        $customer = $this->customer();
        [$property, $roomType] = $this->bookableProperty();

        $this->actingAs($customer)
            ->post(route('accommodation.book', $property->slug), $this->bookingPayload([
                'property_room_type_id' => $roomType->getKey(),
                'idempotency_key' => (string) Str::uuid(),
            ]))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, PropertyBooking::query()->count());
    }

    public function test_a_customer_sees_their_own_stay_and_not_another(): void
    {
        $customer = $this->customer();
        [$property, $roomType] = $this->bookableProperty();

        $mine = PropertyBooking::factory()->forStay($property, $roomType)->forCustomer($customer)->create();
        $theirs = PropertyBooking::factory()->forStay($property, $roomType)
            ->forCustomer($this->customer())->create();

        $this->actingAs($customer)
            ->get(route('portal.property-bookings.show', ['customerPropertyBooking' => $mine->reference]))
            ->assertOk();

        // A foreign reference is a 404, not a 403: it tells the caller nothing.
        $this->actingAs($customer)
            ->get(route('portal.property-bookings.show', ['customerPropertyBooking' => $theirs->reference]))
            ->assertNotFound();
    }

    public function test_the_console_is_closed_to_customers(): void
    {
        $this->actingAs($this->customer())
            ->get(route('admin.accommodation.index'))
            ->assertForbidden();
    }

    public function test_staff_can_work_the_console(): void
    {
        $staff = $this->staff();
        [$property, $roomType] = $this->bookableProperty();
        PropertyBooking::factory()->forStay($property, $roomType)->forCustomer($this->customer())->create();

        $this->actingAs($staff)->get(route('admin.accommodation.index'))->assertOk()->assertSee($property->name);
        $this->actingAs($staff)->get(route('admin.accommodation.show', $property))->assertOk();
        $this->actingAs($staff)->get(route('admin.accommodation.create'))->assertOk();
        $this->actingAs($staff)->get(route('admin.accommodation.bookings.index'))->assertOk();
    }

    public function test_a_manager_confirms_a_stay_through_the_console(): void
    {
        $manager = $this->manager();
        [$property, $roomType] = $this->bookableProperty();

        $booking = PropertyBooking::factory()->forStay($property, $roomType)
            ->forCustomer($this->customer())
            ->status(PropertyBookingStatus::Pending)->create();

        $this->actingAs($manager)
            ->withSession($this->verifiedSession($manager))
            ->post(route('admin.accommodation.bookings.confirm', $booking))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(PropertyBookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_a_stay_is_reachable_from_the_unified_booking_list(): void
    {
        $staff = $this->staff();
        [$property, $roomType] = $this->bookableProperty();

        $booking = PropertyBooking::factory()->forStay($property, $roomType)
            ->forCustomer($this->customer())
            ->status(PropertyBookingStatus::Confirmed)->create();

        $this->actingAs($staff)
            ->get(route('admin.bookings.index', ['source' => 'accommodation']))
            ->assertOk()
            ->assertSee($booking->reference);
    }
}
