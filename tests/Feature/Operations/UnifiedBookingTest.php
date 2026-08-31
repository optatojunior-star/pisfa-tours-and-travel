<?php

namespace Tests\Feature\Operations;

use App\Enums\AirportTransferBookingStatus;
use App\Enums\BookingStage;
use App\Enums\CarHireBookingStatus;
use App\Enums\TourBookingStatus;
use App\Enums\UserRole;
use App\Enums\VehicleImportStatus;
use App\Models\AirportTransferBooking;
use App\Models\CarHireBooking;
use App\Models\User;
use App\Models\VehicleImportOrder;
use App\Services\Bookings\UnifiedBookingQuery;
use App\Support\Bookings\BookingSource;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class UnifiedBookingTest extends TestCase
{
    use BuildsTourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();
    }

    private function staff(): User
    {
        return $this->user(UserRole::Staff, ['two_factor_required' => false]);
    }

    /** One record in each of the four domains, so the union has something real. */
    private function seedOneOfEach(): void
    {
        $customer = $this->customer();

        $this->persistedBooking($customer, $this->bookableDeparture(), TourBookingStatus::Pending);
        CarHireBooking::factory()->create([
            'customer_id' => $customer->getKey(),
            'status' => CarHireBookingStatus::Confirmed,
            'contact_name' => 'Hire Customer',
            'contact_email' => 'hire@example.com',
        ]);
        AirportTransferBooking::factory()->create([
            'customer_id' => $customer->getKey(),
            'status' => AirportTransferBookingStatus::Completed,
            'contact_name' => 'Transfer Customer',
            'contact_email' => 'transfer@example.com',
        ]);
        VehicleImportOrder::factory()->create([
            'customer_id' => $customer->getKey(),
            'status' => VehicleImportStatus::Inquiry,
            'contact_name' => 'Import Customer',
            'contact_email' => 'import@example.com',
        ]);
    }

    // ---- Stage mapping ---------------------------------------------------

    public function test_every_domain_status_maps_to_a_stage(): void
    {
        // A status with no stage would silently vanish from the unified list.
        foreach (TourBookingStatus::cases() as $case) {
            $this->assertInstanceOf(BookingStage::class, $case->stage());
        }
        foreach (CarHireBookingStatus::cases() as $case) {
            $this->assertInstanceOf(BookingStage::class, $case->stage());
        }
        foreach (AirportTransferBookingStatus::cases() as $case) {
            $this->assertInstanceOf(BookingStage::class, $case->stage());
        }
        foreach (VehicleImportStatus::cases() as $case) {
            $this->assertInstanceOf(BookingStage::class, $case->stage());
        }
    }

    public function test_every_status_appears_in_exactly_one_stage_bucket(): void
    {
        foreach (BookingSource::cases() as $source) {
            $seen = [];

            foreach (BookingStage::cases() as $stage) {
                foreach ($source->statusValuesInStage($stage) as $value) {
                    $this->assertArrayNotHasKey(
                        $value,
                        $seen,
                        $source->value.' status '.$value.' is in more than one stage.',
                    );
                    $seen[$value] = $stage;
                }
            }

            $this->assertNotSame([], $seen, $source->value.' has no statuses mapped.');
        }
    }

    public function test_a_declined_transfer_and_a_cancelled_import_share_the_closed_stage(): void
    {
        // The point of the shared vocabulary: an operator asks one question and
        // gets the right rows from domains that name things differently.
        $this->assertSame(BookingStage::Closed, AirportTransferBookingStatus::Declined->stage());
        $this->assertSame(BookingStage::Closed, VehicleImportStatus::Cancelled->stage());
        $this->assertSame(BookingStage::Closed, CarHireBookingStatus::Expired->stage());
    }

    public function test_an_import_is_not_confirmed_until_the_deposit_arrives(): void
    {
        // A quotation the customer has not paid is still awaiting them.
        $this->assertSame(BookingStage::AwaitingAction, VehicleImportStatus::Quoted->stage());
        $this->assertSame(BookingStage::Confirmed, VehicleImportStatus::DepositPaid->stage());
    }

    // ---- The union query -------------------------------------------------

    public function test_the_list_draws_from_all_four_domains(): void
    {
        $this->seedOneOfEach();

        $results = app(UnifiedBookingQuery::class)->paginate([]);

        $this->assertSame(4, $results->total());
        $this->assertEqualsCanonicalizing(
            ['tours', 'car-hire', 'airport-transfers', 'vehicle-imports'],
            $results->getCollection()->pluck('source')->all(),
        );
    }

    public function test_filtering_by_source_narrows_to_one_domain(): void
    {
        $this->seedOneOfEach();

        $results = app(UnifiedBookingQuery::class)->paginate(['source' => 'car-hire']);

        $this->assertSame(1, $results->total());
        $this->assertSame('car-hire', $results->getCollection()->first()->source);
    }

    public function test_an_unknown_source_returns_nothing_rather_than_everything(): void
    {
        $this->seedOneOfEach();

        $results = app(UnifiedBookingQuery::class)->paginate(['source' => 'not-a-service']);

        $this->assertSame(0, $results->total());
    }

    public function test_an_unknown_stage_returns_nothing_rather_than_everything(): void
    {
        $this->seedOneOfEach();

        $results = app(UnifiedBookingQuery::class)->paginate(['stage' => 'not-a-stage']);

        $this->assertSame(0, $results->total());
    }

    public function test_filtering_by_stage_crosses_domains(): void
    {
        $this->seedOneOfEach();

        $awaiting = app(UnifiedBookingQuery::class)
            ->paginate(['stage' => BookingStage::AwaitingAction->value]);

        // A pending tour and an import enquiry, from two different tables.
        $this->assertSame(2, $awaiting->total());
        $this->assertEqualsCanonicalizing(
            ['tours', 'vehicle-imports'],
            $awaiting->getCollection()->pluck('source')->all(),
        );
    }

    public function test_search_matches_reference_name_and_email_across_domains(): void
    {
        $this->seedOneOfEach();
        $query = app(UnifiedBookingQuery::class);

        $this->assertSame(1, $query->paginate(['q' => 'transfer@example.com'])->total());
        $this->assertSame(1, $query->paginate(['q' => 'Import Customer'])->total());
        $this->assertSame(0, $query->paginate(['q' => 'nobody@example.com'])->total());
    }

    public function test_stage_counts_cover_every_stage(): void
    {
        $this->seedOneOfEach();

        $counts = app(UnifiedBookingQuery::class)->stageCounts([]);

        foreach (BookingStage::cases() as $stage) {
            $this->assertArrayHasKey($stage->value, $counts);
        }

        $this->assertSame(2, $counts[BookingStage::AwaitingAction->value]);
        $this->assertSame(1, $counts[BookingStage::Confirmed->value]);
        $this->assertSame(1, $counts[BookingStage::Completed->value]);
        $this->assertSame(0, $counts[BookingStage::Closed->value]);
    }

    public function test_the_date_range_is_read_in_kampala_terms(): void
    {
        $customer = $this->customer();

        // 22:30 Kampala on the 25th is 19:30 UTC the same day. A naive UTC
        // comparison would put it outside a 25th-to-25th range.
        $departure = $this->bookableDeparture(null, [
            'starts_at' => CarbonImmutable::parse('2026-08-25 22:30:00', 'Africa/Kampala')->utc(),
            'ends_at' => CarbonImmutable::parse('2026-08-27 10:00:00', 'Africa/Kampala')->utc(),
            'cancellation_cutoff_at' => CarbonImmutable::parse('2026-08-23 10:00:00', 'Africa/Kampala')->utc(),
        ]);
        $this->persistedBooking($customer, $departure, TourBookingStatus::Confirmed);

        $results = app(UnifiedBookingQuery::class)->paginate([
            'source' => 'tours',
            'from' => '2026-08-25',
            'to' => '2026-08-25',
        ]);

        $this->assertSame(1, $results->total());
    }

    public function test_an_unpriced_import_still_appears_with_a_zero_amount(): void
    {
        VehicleImportOrder::factory()->create([
            'status' => VehicleImportStatus::Inquiry,
            'total_price_minor' => null,
        ]);

        $results = app(UnifiedBookingQuery::class)->paginate(['source' => 'vehicle-imports']);

        // An unpriced enquiry is still work in the queue; dropping it would hide
        // exactly the rows that most need attention.
        $this->assertSame(1, $results->total());
        $this->assertSame(0, (int) $results->getCollection()->first()->amount_minor);
    }

    public function test_sorting_by_amount_is_accepted_and_an_unknown_sort_is_ignored(): void
    {
        $this->seedOneOfEach();
        $query = app(UnifiedBookingQuery::class);

        $this->assertSame(4, $query->paginate(['sort' => 'amount', 'direction' => 'asc'])->total());

        // An unvalidated sort column would reach the union's ORDER BY, so
        // anything unrecognised falls back to the default instead.
        $usersBefore = User::query()->count();
        $this->assertSame(4, $query->paginate(['sort' => 'id; drop table users'])->total());
        $this->assertSame($usersBefore, User::query()->count());
    }

    // ---- HTTP ------------------------------------------------------------

    public function test_staff_reach_the_unified_list(): void
    {
        $this->seedOneOfEach();

        $this->actingAs($this->staff())
            ->get(route('admin.bookings.index'))
            ->assertOk()
            ->assertSee('All bookings')
            ->assertSee('Import Customer');
    }

    public function test_a_customer_cannot_reach_the_unified_list(): void
    {
        $this->actingAs($this->customer())
            ->get(route('admin.bookings.index'))
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('admin.bookings.index'))->assertRedirect(route('login'));
    }

    public function test_an_invalid_date_range_is_rejected(): void
    {
        $this->actingAs($this->staff())
            ->get(route('admin.bookings.index', ['from' => '2026-09-01', 'to' => '2026-08-01']))
            ->assertSessionHasErrors('to');
    }

    public function test_the_list_filters_over_http(): void
    {
        $this->seedOneOfEach();

        $this->actingAs($this->staff())
            ->get(route('admin.bookings.index', ['source' => 'vehicle-imports']))
            ->assertOk()
            ->assertSee('Import Customer')
            ->assertDontSee('Transfer Customer');
    }
}
