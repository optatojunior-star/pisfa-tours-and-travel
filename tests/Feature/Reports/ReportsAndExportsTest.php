<?php

namespace Tests\Feature\Reports;

use App\Actions\Payments\CreatePaymentIntent;
use App\Actions\Payments\SettlePayment;
use App\Enums\InvoiceStatus;
use App\Enums\PaymentProvider;
use App\Enums\TourBookingStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Reports\OperationsReport;
use App\Services\Reports\RevenueReport;
use App\Support\Export\CsvCell;
use App\Support\Export\ExportDataset;
use App\Support\Reports\ReportPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class ReportsAndExportsTest extends TestCase
{
    use BuildsTourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();

        // Managers sit under the mandatory-2FA policy, which would redirect
        // before any report route is reached. That gate has its own coverage.
        config(['security.two_factor.required_roles' => []]);
    }

    private function staff(): User
    {
        return $this->user(UserRole::Staff, ['two_factor_required' => false]);
    }

    private function manager(): User
    {
        return $this->user(UserRole::Manager, ['two_factor_required' => false]);
    }

    private function settledInvoice(User $customer, string $currency = 'UGX', int $unitMinor = 5_000_000): Invoice
    {
        $invoice = Invoice::factory()
            ->forCustomer($customer)
            ->withItems([['description' => 'Safari', 'quantity' => 1, 'unit_price_minor' => $unitMinor]])
            ->withStatus(InvoiceStatus::Issued)
            ->create(['currency' => $currency])
            ->fresh('items');

        $payment = app(CreatePaymentIntent::class)->execute(
            $customer,
            $invoice,
            PaymentProvider::BankTransfer,
            (string) Str::uuid(),
        );
        app(SettlePayment::class)->execute(payment: $payment, actor: $this->staff(), source: 'manual');

        return $invoice->fresh();
    }

    // ---- CSV injection, the security property ----------------------------

    public function test_a_formula_cell_is_neutralised(): void
    {
        // A stored name beginning with a formula trigger would execute in Excel
        // on the machine of whoever opened the export.
        foreach (['=HYPERLINK("http://evil","x")', '+1+1', '-1+1', '@SUM(A1)', "\tx", "\rx"] as $payload) {
            $safe = CsvCell::safe($payload);

            $this->assertStringStartsWith("'", $safe, "Payload was not neutralised: {$payload}");
        }
    }

    public function test_ordinary_text_is_left_alone(): void
    {
        $this->assertSame('Grace Nakato', CsvCell::safe('Grace Nakato'));
        $this->assertSame('UGX 5,000', CsvCell::safe('UGX 5,000'));
        $this->assertSame('', CsvCell::safe(null));
        $this->assertSame('yes', CsvCell::safe(true));
    }

    public function test_control_characters_are_stripped(): void
    {
        // These can break a row apart or hide content from a reviewer.
        $this->assertSame('abc', CsvCell::safe("a\x00b\x01c"));
    }

    public function test_a_malicious_customer_name_reaches_the_file_escaped(): void
    {
        $customer = $this->customer(['name' => '=cmd|"/c calc"!A1']);
        $this->settledInvoice($customer);

        $csv = $this->actingAs($this->manager())
            ->get(route('admin.reports.export', ['customers', 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString("'=cmd", $csv);
        // The raw trigger must never begin a cell.
        $this->assertStringNotContainsString(',=cmd', $csv);
    }

    // ---- The period ------------------------------------------------------

    public function test_a_period_is_read_in_kampala_terms(): void
    {
        $period = ReportPeriod::between('2026-08-20', '2026-08-20');

        // A Kampala day starts at 21:00 UTC the previous day.
        $this->assertSame('2026-08-19 21:00:00', $period->startsAt->format('Y-m-d H:i:s'));
        $this->assertSame('2026-08-20 20:59:59', $period->endsAt->format('Y-m-d H:i:s'));
        $this->assertSame(1, $period->days());
    }

    public function test_a_reversed_range_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ReportPeriod::between('2026-09-01', '2026-08-01');
    }

    public function test_an_unbounded_range_is_refused(): void
    {
        // A report has to bound its query cost, or one URL can take the site down.
        $this->expectException(InvalidArgumentException::class);

        ReportPeriod::between('2020-01-01', '2026-12-31');
    }

    public function test_the_previous_period_is_the_same_length(): void
    {
        $period = ReportPeriod::between('2026-08-01', '2026-08-31');
        $previous = $period->previous();

        $this->assertSame($period->days(), $previous->days());
        $this->assertSame('2026-07-31', $previous->endDate->toDateString());
    }

    // ---- Revenue ---------------------------------------------------------

    public function test_revenue_reports_each_currency_separately(): void
    {
        $customer = $this->customer();
        $this->settledInvoice($customer, 'UGX', 5_000_000);
        $this->settledInvoice($customer, 'USD', 20_000);

        $summary = app(RevenueReport::class)->summary(ReportPeriod::lastDays(30));

        $this->assertSame(2, $summary['current']['payments']);
        // UGX and USD have different exponents, so the received amounts stay
        // apart; only the base figure is a single total.
        $this->assertSame(5_000_000, $summary['by_currency']['UGX']['collected_minor']);
        $this->assertSame(20_000, $summary['by_currency']['USD']['collected_minor']);
        $this->assertGreaterThan(0, $summary['current']['base_total_minor']);
    }

    public function test_revenue_is_attributed_to_the_service_that_earned_it(): void
    {
        $customer = $this->customer();
        $this->settledInvoice($customer);

        $summary = app(RevenueReport::class)->summary(ReportPeriod::lastDays(30));

        $this->assertArrayHasKey('Invoice', $summary['by_service']);
        $this->assertSame(1, $summary['by_service']['Invoice']['payments']);
    }

    public function test_the_daily_series_covers_every_day_including_empty_ones(): void
    {
        $period = ReportPeriod::between('2026-08-18', '2026-08-20');
        $this->settledInvoice($this->customer());

        $summary = app(RevenueReport::class)->summary($period);

        // A gap in trade should read as a gap, not as missing data.
        $this->assertCount(3, $summary['daily']);
        $this->assertSame(0, $summary['daily']['2026-08-18']);
        $this->assertGreaterThan(0, $summary['daily']['2026-08-20']);
    }

    public function test_a_period_with_no_previous_takings_reports_no_change(): void
    {
        $this->settledInvoice($this->customer());

        $summary = app(RevenueReport::class)->summary(ReportPeriod::lastDays(30));

        // A percentage against zero is not a number worth showing.
        $this->assertNull($summary['change_percent']);
    }

    // ---- Operations ------------------------------------------------------

    public function test_bookings_are_counted_by_when_they_arrived(): void
    {
        $customer = $this->customer();
        $this->persistedBooking($customer, $this->bookableDeparture(), TourBookingStatus::Pending);
        $this->persistedBooking($customer, $this->bookableDeparture(), TourBookingStatus::Completed);

        $summary = app(OperationsReport::class)->summary(ReportPeriod::lastDays(30));

        $this->assertSame(2, $summary['bookings']['by_source']['tours']['total']);
        $this->assertSame(1, $summary['bookings']['by_source']['tours']['by_stage']['awaiting_action']);
        $this->assertSame(1, $summary['bookings']['by_source']['tours']['by_stage']['completed']);
    }

    public function test_a_booking_outside_the_window_is_not_counted(): void
    {
        $this->persistedBooking($this->customer(), $this->bookableDeparture(), TourBookingStatus::Pending);

        $summary = app(OperationsReport::class)
            ->summary(ReportPeriod::between('2026-07-01', '2026-07-31'));

        $this->assertSame(0, $summary['bookings']['total']);
    }

    public function test_an_acceptance_rate_needs_a_denominator(): void
    {
        $summary = app(OperationsReport::class)->summary(ReportPeriod::lastDays(30));

        // Without one it is a division by zero dressed up as a percentage.
        $this->assertNull($summary['conversion']['acceptance_rate']);
    }

    // ---- Access ----------------------------------------------------------

    public function test_staff_see_the_operational_half_and_not_the_money(): void
    {
        $this->settledInvoice($this->customer());

        $this->actingAs($this->staff())
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('Revenue is restricted')
            ->assertSee('Operations');
    }

    public function test_a_manager_sees_revenue(): void
    {
        $this->settledInvoice($this->customer());

        $this->actingAs($this->manager())
            ->get(route('admin.reports.index'))
            ->assertOk()
            ->assertSee('Collected')
            ->assertDontSee('Revenue is restricted');
    }

    public function test_a_customer_cannot_reach_reports(): void
    {
        $this->actingAs($this->customer())->get(route('admin.reports.index'))->assertForbidden();
        $this->actingAs($this->customer())
            ->get(route('admin.reports.export', ['bookings']))
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('admin.reports.index'))->assertRedirect(route('login'));
    }

    public function test_staff_cannot_export_a_financial_dataset(): void
    {
        // A booking list is what staff work from; a payments extract is the
        // accounting record.
        $this->assertTrue(ExportDataset::Bookings->isAvailableTo($this->staff()));
        $this->assertFalse(ExportDataset::Payments->isAvailableTo($this->staff()));
        $this->assertFalse(ExportDataset::Customers->isAvailableTo($this->staff()));

        $this->actingAs($this->staff())
            ->get(route('admin.reports.export', ['payments']))
            ->assertForbidden();
    }

    public function test_an_unknown_dataset_is_a_404(): void
    {
        $this->actingAs($this->manager())
            ->get(route('admin.reports.export', ['users']))
            ->assertNotFound();
    }

    // ---- Exports ---------------------------------------------------------

    public function test_a_bookings_export_streams_a_csv(): void
    {
        $customer = $this->customer(['name' => 'Grace Nakato']);
        $booking = $this->persistedBooking($customer, $this->bookableDeparture(), TourBookingStatus::Confirmed);

        $response = $this->actingAs($this->staff())
            ->get(route('admin.reports.export', ['bookings', 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Service,Reference,Status', $csv);
        $this->assertStringContainsString($booking->reference, $csv);
        $this->assertStringContainsString('Grace Nakato', $csv);
    }

    public function test_a_payments_export_never_carries_a_secret(): void
    {
        $customer = $this->customer();
        $this->settledInvoice($customer);

        $csv = $this->actingAs($this->manager())
            ->get(route('admin.reports.export', ['payments', 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Base amount', $csv);
        // An export leaves the building; nothing that authorises anything may
        // travel with it.
        foreach (['idempotency', 'webhook', 'secret', 'token', 'password'] as $forbidden) {
            $this->assertStringNotContainsStringIgnoringCase($forbidden, $csv);
        }
    }

    public function test_a_customers_export_carries_no_credentials(): void
    {
        $customer = $this->customer(['name' => 'Peter Okello']);
        $this->settledInvoice($customer);

        $csv = $this->actingAs($this->manager())
            ->get(route('admin.reports.export', ['customers', 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString('Peter Okello', $csv);
        $this->assertStringNotContainsString($customer->password, $csv);
        $this->assertStringNotContainsStringIgnoringCase('remember_token', $csv);
    }

    public function test_an_export_is_recorded_in_the_audit_log(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager)
            ->get(route('admin.reports.export', ['payments', 'from' => '2026-08-01', 'to' => '2026-08-31']))
            ->assertOk()
            ->streamedContent();

        // Taking a copy of payment data out of the system is a data-access
        // event, not a page view.
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'export.generated',
            'user_id' => $manager->getKey(),
        ]);
    }

    public function test_an_export_of_an_impossible_range_is_refused(): void
    {
        $this->actingAs($this->manager())
            ->get(route('admin.reports.export', ['bookings', 'from' => '2026-09-01', 'to' => '2026-08-01']))
            ->assertStatus(422);
    }

    public function test_a_report_range_beyond_the_maximum_is_a_validation_error(): void
    {
        $this->actingAs($this->manager())
            ->get(route('admin.reports.index', ['from' => '2019-01-01', 'to' => '2026-12-31']))
            ->assertSessionHasErrors('to');
    }

    public function test_an_export_with_no_rows_still_returns_a_valid_file(): void
    {
        $csv = $this->actingAs($this->manager())
            ->get(route('admin.reports.export', ['invoices', 'from' => '2026-01-01', 'to' => '2026-01-31']))
            ->assertOk()
            ->streamedContent();

        // Headings and a byte-order mark, so Excel opens it correctly even
        // when there is nothing to show.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Number,Status', $csv);
    }
}
