<?php

namespace Tests\Feature\Operations;

use App\Actions\Payments\CreatePaymentIntent;
use App\Actions\Payments\SettlePayment;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentProvider;
use App\Enums\ReviewStatus;
use App\Enums\TourBookingStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\QuotationRequest;
use App\Models\Review;
use App\Models\User;
use App\Services\Dashboard\CustomerSnapshot;
use App\Services\Dashboard\OperationsSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class DashboardTest extends TestCase
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

    private function issuedInvoice(User $customer, array $overrides = []): Invoice
    {
        return Invoice::factory()
            ->forCustomer($customer)
            ->withItems([['description' => 'Safari', 'quantity' => 1, 'unit_price_minor' => 5_000_000]])
            ->withStatus(InvoiceStatus::Issued)
            ->create($overrides)
            ->fresh('items');
    }

    // ---- Routing ---------------------------------------------------------

    public function test_each_role_lands_on_its_own_dashboard(): void
    {
        $this->actingAs($this->staff())
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Needs attention');

        // Customers now have the F13 portal; the shared entry point hands
        // them to it rather than rendering a dashboard of its own.
        $this->actingAs($this->customer())
            ->get(route('dashboard'))
            ->assertRedirect(route('portal.index'));

        // Drivers now have their own portal (F22), so the shared entry point
        // hands them to it rather than to the "not built" placeholder.
        $this->actingAs($this->driver())
            ->get(route('dashboard'))
            ->assertRedirect(route('drivers.index'));
    }

    public function test_a_guest_cannot_reach_the_dashboard(): void
    {
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
    }

    public function test_a_customer_cannot_reach_the_admin_dashboard_url(): void
    {
        $this->actingAs($this->customer())
            ->get(route('admin.dashboard'))
            ->assertForbidden();
    }

    // ---- Operations figures ----------------------------------------------

    public function test_queues_count_work_that_is_actually_waiting(): void
    {
        $customer = $this->customer();
        $this->persistedBooking($customer, $this->bookableDeparture(), TourBookingStatus::Pending);
        $reviewed = $this->persistedBooking($customer, $this->bookableDeparture(), TourBookingStatus::Completed);
        $alsoReviewed = $this->persistedBooking($customer, $this->bookableDeparture(), TourBookingStatus::Completed);
        QuotationRequest::factory()->create();

        // Attached to completed bookings on purpose: letting the factory mint
        // its own would add pending bookings and skew the queue this asserts.
        Review::factory()->forBooking($reviewed)->about($reviewed->tourPackage)
            ->withStatus(ReviewStatus::Pending)->create();
        Review::factory()->forBooking($alsoReviewed)->about($alsoReviewed->tourPackage)
            ->withStatus(ReviewStatus::Published)->create();

        $queues = app(OperationsSnapshot::class)->forDate()['queues'];

        // Only the pending booking is waiting; the completed ones are done.
        $this->assertSame(1, $queues['bookings_awaiting_action']);
        $this->assertSame(1, $queues['new_quotation_requests']);
        $this->assertSame(1, $queues['reviews_awaiting_moderation']);
    }

    public function test_today_counts_only_services_dated_today_in_kampala(): void
    {
        $customer = $this->customer();

        // 23:30 Kampala today is 20:30 UTC today; a naive UTC window would still
        // include it, but 00:30 Kampala tomorrow is 21:30 UTC *today* and must
        // not be counted as today's work.
        $tonight = $this->bookableDeparture(null, [
            'starts_at' => CarbonImmutable::parse('2026-08-20 23:30:00', 'Africa/Kampala')->utc(),
            'ends_at' => CarbonImmutable::parse('2026-08-22 10:00:00', 'Africa/Kampala')->utc(),
            'cancellation_cutoff_at' => CarbonImmutable::parse('2026-08-18 10:00:00', 'Africa/Kampala')->utc(),
        ]);
        $tomorrow = $this->bookableDeparture(null, [
            'starts_at' => CarbonImmutable::parse('2026-08-21 00:30:00', 'Africa/Kampala')->utc(),
            'ends_at' => CarbonImmutable::parse('2026-08-23 10:00:00', 'Africa/Kampala')->utc(),
            'cancellation_cutoff_at' => CarbonImmutable::parse('2026-08-18 10:00:00', 'Africa/Kampala')->utc(),
        ]);

        $this->persistedBooking($customer, $tonight, TourBookingStatus::Confirmed);
        $this->persistedBooking($customer, $tomorrow, TourBookingStatus::Confirmed);

        $today = app(OperationsSnapshot::class)->forDate()['today'];

        $this->assertSame(1, $today['sources']['tours']['count']);
    }

    public function test_imports_are_excluded_from_todays_service(): void
    {
        $today = app(OperationsSnapshot::class)->forDate()['today'];

        // An import runs for months and has no service date. Counting it would
        // report months of work as happening today.
        $this->assertArrayNotHasKey('vehicle-imports', $today['sources']);
    }

    public function test_revenue_is_reported_per_currency_and_never_added_across_them(): void
    {
        $customer = $this->customer();
        $staff = $this->staff();

        foreach (['UGX', 'USD'] as $currency) {
            $invoice = $this->issuedInvoice($customer, ['currency' => $currency]);
            $payment = app(CreatePaymentIntent::class)->execute(
                $customer,
                $invoice,
                PaymentProvider::BankTransfer,
                (string) Str::uuid(),
            );
            app(SettlePayment::class)->execute(payment: $payment, actor: $staff, source: 'manual');
        }

        $revenue = app(OperationsSnapshot::class)->forDate()['revenue'];

        $this->assertSame(2, $revenue['payments']);
        $this->assertArrayHasKey('UGX', $revenue['by_currency']);
        $this->assertArrayHasKey('USD', $revenue['by_currency']);
        // The raw minor units are kept apart; only the base-converted figure is
        // a single number, because it was converted at the stamped rate.
        $this->assertNotSame(
            $revenue['by_currency']['UGX']['collected_minor'] + $revenue['by_currency']['USD']['collected_minor'],
            $revenue['base_total_minor'],
        );
    }

    public function test_receivables_report_outstanding_balances_per_currency(): void
    {
        $customer = $this->customer();
        $this->issuedInvoice($customer, ['currency' => 'UGX']);
        $this->issuedInvoice($customer, ['currency' => 'UGX']);
        // A paid invoice owes nothing and must not inflate receivables.
        Invoice::factory()
            ->forCustomer($customer)
            ->withItems([['description' => 'Done', 'quantity' => 1, 'unit_price_minor' => 900_000]])
            ->withStatus(InvoiceStatus::Paid)
            ->create(['currency' => 'UGX']);

        $receivables = app(OperationsSnapshot::class)->forDate()['receivables'];

        $this->assertSame(2, $receivables['UGX']['count']);
        $this->assertSame(10_000_000, $receivables['UGX']['outstanding_minor']);
    }

    public function test_an_overdue_invoice_is_flagged_in_the_receivables_and_the_queue(): void
    {
        $customer = $this->customer();
        $this->issuedInvoice($customer, ['due_on' => '2026-08-10']);

        $snapshot = app(OperationsSnapshot::class)->forDate();

        $this->assertSame(1, $snapshot['queues']['overdue_invoices']);
        $this->assertSame(5_000_000, $snapshot['receivables']['UGX']['overdue_minor']);
    }

    public function test_the_admin_dashboard_renders_the_live_figures(): void
    {
        $customer = $this->customer();
        $this->persistedBooking($customer, $this->bookableDeparture(), TourBookingStatus::Pending);

        $this->actingAs($this->staff())
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('Bookings awaiting action')
            ->assertSee('Outstanding receivables')
            ->assertSee('Africa/Kampala');
    }

    // ---- Customer figures ------------------------------------------------

    public function test_a_customer_only_ever_sees_their_own_numbers(): void
    {
        $mine = $this->customer();
        $theirs = $this->customer();

        $this->persistedBooking($mine, $this->bookableDeparture(), TourBookingStatus::Confirmed);
        $this->persistedBooking($theirs, $this->bookableDeparture(), TourBookingStatus::Confirmed);
        $this->persistedBooking($theirs, $this->bookableDeparture(), TourBookingStatus::Pending);

        $snapshot = app(CustomerSnapshot::class)->forCustomer($mine);

        $this->assertSame(1, $snapshot['open_bookings']);
    }

    public function test_a_customers_upcoming_list_is_soonest_first_and_capped(): void
    {
        $customer = $this->customer();

        foreach ([12, 3, 30, 7, 21, 45] as $days) {
            $departure = $this->bookableDeparture(null, [
                'starts_at' => now()->toImmutable()->addDays($days),
                'ends_at' => now()->toImmutable()->addDays($days + 2),
                'cancellation_cutoff_at' => now()->toImmutable()->addDays($days - 2),
            ]);
            $this->persistedBooking($customer, $departure, TourBookingStatus::Confirmed);
        }

        $upcoming = app(CustomerSnapshot::class)->forCustomer($customer)['upcoming'];

        $this->assertCount(5, $upcoming);
        $dates = array_map(static fn (array $row) => $row['service_date']->timestamp, $upcoming);
        $sorted = $dates;
        sort($sorted);
        $this->assertSame($sorted, $dates);
    }

    public function test_a_past_booking_is_not_upcoming(): void
    {
        $customer = $this->customer();
        $this->persistedBooking($customer, $this->bookableDeparture(), TourBookingStatus::Completed);

        $this->assertSame([], app(CustomerSnapshot::class)->forCustomer($customer)['upcoming']);
    }

    public function test_the_customer_portal_shows_amounts_due(): void
    {
        $customer = $this->customer();
        $this->issuedInvoice($customer, ['due_on' => '2026-08-10']);

        $this->actingAs($customer)
            ->get(route('portal.index'))
            ->assertOk()
            ->assertSee('Amounts due')
            ->assertSee('UGX 5,000,000')
            ->assertSee('1 overdue');
    }

    public function test_a_customer_with_nothing_gets_an_honest_empty_state(): void
    {
        $this->actingAs($this->customer())
            ->get(route('portal.index'))
            ->assertOk()
            ->assertSee('Nothing is scheduled yet')
            ->assertDontSee('Amounts due');
    }
}
