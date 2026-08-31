<?php

namespace Tests\Feature\Tours;

use App\Models\TourBooking;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Pagination\LengthAwarePaginator;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class TourBookingIndexDateFilterTest extends TestCase
{
    use BuildsTourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
    }

    public function test_customer_date_filter_uses_half_open_business_day_boundaries_in_utc(): void
    {
        $customer = $this->customer();
        [$previousDay, $selectedDay, $nextDay] = $this->bookingsAroundLocalMidnight($customer);

        $response = $this->actingAs($customer)
            ->get(route('portal.bookings.index', [
                'from' => '2026-09-10',
                'to' => '2026-09-10',
                'period' => 'all',
            ]))
            ->assertOk();

        /** @var LengthAwarePaginator $bookings */
        $bookings = $response->viewData('bookings');
        $this->assertSame([$selectedDay->getKey()], $bookings->getCollection()->modelKeys());
        $response
            ->assertSee($selectedDay->reference)
            ->assertDontSee($previousDay->reference)
            ->assertDontSee($nextDay->reference);
    }

    public function test_administration_date_filter_uses_the_same_half_open_business_day_boundaries(): void
    {
        $customer = $this->customer();
        [$previousDay, $selectedDay, $nextDay] = $this->bookingsAroundLocalMidnight($customer);

        $response = $this->actingAs($this->operationsUser())
            ->get(route('admin.tour-bookings.index', [
                'from' => '2026-09-10',
                'to' => '2026-09-10',
                'period' => 'all',
            ]))
            ->assertOk();

        /** @var LengthAwarePaginator $bookings */
        $bookings = $response->viewData('bookings');
        $this->assertSame([$selectedDay->getKey()], $bookings->getCollection()->modelKeys());
        $response
            ->assertSee($selectedDay->reference)
            ->assertDontSee($previousDay->reference)
            ->assertDontSee($nextDay->reference);
    }

    /** @return array{TourBooking, TourBooking, TourBooking} */
    private function bookingsAroundLocalMidnight(User $customer): array
    {
        $departure = $this->bookableDeparture();
        $businessTimezone = config('pisfa.business_timezone', 'Africa/Kampala');
        $previousStart = CarbonImmutable::parse('2026-09-09 23:59:00', $businessTimezone)->utc();
        $selectedStart = CarbonImmutable::parse('2026-09-10 00:30:00', $businessTimezone)->utc();
        $nextStart = CarbonImmutable::parse('2026-09-11 00:00:00', $businessTimezone)->utc();

        $create = fn (string $reference, CarbonImmutable $startsAt): TourBooking => $this->persistedBooking(
            $customer,
            $departure,
            attributes: [
                'reference' => $reference,
                'departure_starts_at_snapshot' => $startsAt,
                'departure_ends_at_snapshot' => $startsAt->addDay(),
                'cancellation_cutoff_at_snapshot' => $startsAt->subDay(),
            ],
        );

        return [
            $create('PREVIOUS-LOCAL-DAY', $previousStart),
            $create('SELECTED-LOCAL-DAY', $selectedStart),
            $create('NEXT-LOCAL-DAY', $nextStart),
        ];
    }
}
