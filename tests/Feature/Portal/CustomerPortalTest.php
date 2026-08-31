<?php

namespace Tests\Feature\Portal;

use App\Actions\Billing\ConvertQuotationToInvoice;
use App\Actions\Billing\SaveQuotation;
use App\Actions\Billing\TransitionInvoice;
use App\Actions\Billing\TransitionQuotation;
use App\Enums\InvoiceStatus;
use App\Enums\QuotationStatus;
use App\Enums\TourBookingStatus;
use App\Enums\UserRole;
use App\Models\Invoice;
use App\Models\Quotation;
use App\Models\User;
use App\Notifications\Tours\TourBookingConfirmedNotification;
use App\Services\Portal\CustomerActivityQuery;
use App\Services\Portal\CustomerDocumentQuery;
use App\Support\Portal\ActivityKind;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class CustomerPortalTest extends TestCase
{
    use BuildsTourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
        Storage::fake('local');
    }

    private function staff(): User
    {
        return $this->user(UserRole::Staff, ['two_factor_required' => false]);
    }

    /** @return array<string, mixed> */
    private function quotationPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Bwindi gorilla trek',
            'currency' => 'UGX',
            'contact_name' => 'Grace Nakato',
            'contact_email' => 'grace@example.com',
            'contact_phone' => '+256700000111',
            'valid_until' => now()->addDays(14)->toDateString(),
            'tax_rate_bps' => 0,
            'items' => [['description' => 'Permit', 'quantity' => 1, 'unit_price' => '2500000']],
        ], $overrides);
    }

    private function sentQuotationFor(User $customer): Quotation
    {
        $staff = $this->staff();
        $quotation = app(SaveQuotation::class)->create($staff, $this->quotationPayload());
        $quotation->forceFill(['customer_id' => $customer->getKey()])->save();
        app(TransitionQuotation::class)->send($staff, $quotation);

        return $quotation->fresh(['items']);
    }

    private function issuedInvoiceFor(User $customer): Invoice
    {
        $staff = $this->staff();
        $quotation = $this->sentQuotationFor($customer);
        app(TransitionQuotation::class)->respond($customer, $quotation, true);
        $invoice = app(ConvertQuotationToInvoice::class)->execute($staff, $quotation->fresh());
        app(TransitionInvoice::class)->issue($staff, $invoice);

        return $invoice->fresh(['items']);
    }

    // ---- Routing ---------------------------------------------------------

    public function test_a_customer_lands_on_the_portal(): void
    {
        $customer = $this->customer();

        $this->actingAs($customer)->get(route('dashboard'))->assertRedirect(route('portal.index'));

        $this->actingAs($customer)
            ->get(route('portal.index'))
            ->assertOk()
            ->assertSee('Recent activity');
    }

    public function test_staff_cannot_reach_the_customer_portal(): void
    {
        foreach (['portal.index', 'portal.activity', 'portal.documents', 'portal.notifications.index'] as $route) {
            $this->actingAs($this->staff())->get(route($route))->assertForbidden();
        }
    }

    public function test_a_guest_is_sent_to_login(): void
    {
        $this->get(route('portal.index'))->assertRedirect(route('login'));
        $this->get(route('portal.activity'))->assertRedirect(route('login'));
    }

    // ---- The activity aggregate ------------------------------------------

    public function test_activity_draws_from_every_domain(): void
    {
        $customer = $this->customer();
        $this->persistedBooking($customer, $this->bookableDeparture(), TourBookingStatus::Confirmed);
        $this->sentQuotationFor($customer);
        $this->issuedInvoiceFor($customer);

        $counts = app(CustomerActivityQuery::class)->counts($customer);

        $this->assertSame(1, $counts[ActivityKind::Bookings->value]);
        // Two quotations: the one sent, and the one behind the invoice.
        $this->assertSame(2, $counts[ActivityKind::Quotations->value]);
        $this->assertSame(1, $counts[ActivityKind::Invoices->value]);
    }

    public function test_activity_never_shows_another_customers_rows(): void
    {
        $mine = $this->customer();
        $theirs = $this->customer();

        $this->persistedBooking($mine, $this->bookableDeparture(), TourBookingStatus::Confirmed);
        $this->persistedBooking($theirs, $this->bookableDeparture(), TourBookingStatus::Confirmed);
        $this->persistedBooking($theirs, $this->bookableDeparture(), TourBookingStatus::Pending);

        $items = app(CustomerActivityQuery::class)->paginate($mine);

        $this->assertSame(1, $items->total());
    }

    public function test_a_draft_quotation_never_reaches_the_aggregate(): void
    {
        $customer = $this->customer();
        $draft = app(SaveQuotation::class)->create($this->staff(), $this->quotationPayload());
        $draft->forceFill(['customer_id' => $customer->getKey()])->save();

        $this->assertSame(QuotationStatus::Draft, $draft->fresh()->status);

        // A draft is internal, and an aggregate list is still a customer screen.
        $this->assertSame(0, app(CustomerActivityQuery::class)->counts($customer)[ActivityKind::Quotations->value]);

        $this->actingAs($customer)
            ->get(route('portal.activity'))
            ->assertOk()
            ->assertDontSee($draft->number);
    }

    public function test_an_unknown_kind_returns_nothing_rather_than_everything(): void
    {
        $customer = $this->customer();
        $this->persistedBooking($customer, $this->bookableDeparture(), TourBookingStatus::Confirmed);

        $this->assertSame(
            0,
            app(CustomerActivityQuery::class)->paginate($customer, ['kind' => 'not-a-kind'])->total(),
        );
    }

    public function test_filtering_by_kind_narrows_the_list(): void
    {
        $customer = $this->customer();
        $booking = $this->persistedBooking($customer, $this->bookableDeparture(), TourBookingStatus::Confirmed);
        $quotation = $this->sentQuotationFor($customer);

        $this->actingAs($customer)
            ->get(route('portal.activity', ['kind' => ActivityKind::Quotations->value]))
            ->assertOk()
            ->assertSee($quotation->number)
            ->assertDontSee($booking->reference);
    }

    public function test_search_matches_a_reference(): void
    {
        $customer = $this->customer();
        $booking = $this->persistedBooking($customer, $this->bookableDeparture(), TourBookingStatus::Confirmed);

        $found = app(CustomerActivityQuery::class)->paginate($customer, ['q' => $booking->reference]);
        $this->assertSame(1, $found->total());

        $missing = app(CustomerActivityQuery::class)->paginate($customer, ['q' => 'NOPE-000']);
        $this->assertSame(0, $missing->total());
    }

    public function test_an_invalid_kind_is_a_validation_error_over_http(): void
    {
        $this->actingAs($this->customer())
            ->get(route('portal.activity', ['kind' => 'everything-please']))
            ->assertSessionHasErrors('kind');
    }

    // ---- Documents -------------------------------------------------------

    public function test_the_document_hub_lists_only_the_customers_own(): void
    {
        $mine = $this->customer();
        $theirs = $this->customer();

        $this->issuedInvoiceFor($mine);
        $this->issuedInvoiceFor($theirs);

        $documents = app(CustomerDocumentQuery::class)->forCustomer($mine);

        // A quotation PDF and an invoice PDF, both this customer's.
        $this->assertGreaterThan(0, $documents->count());

        foreach ($documents as $document) {
            // A polymorphic owner has no statically known columns, so the
            // attribute is read by name.
            $this->assertSame(
                $mine->getKey(),
                $document->documentable?->getAttribute('customer_id'),
            );
        }
    }

    public function test_only_current_versions_are_listed(): void
    {
        $customer = $this->customer();
        $staff = $this->staff();
        $quotation = $this->sentQuotationFor($customer);

        // A revision files a second version and supersedes the first.
        app(TransitionQuotation::class)->revise($staff, $quotation->fresh());
        app(TransitionQuotation::class)->send($staff, $quotation->fresh());

        $this->assertSame(2, $quotation->documents()->count());

        $listed = app(CustomerDocumentQuery::class)->forCustomer($customer)
            ->where('documentable_type', $quotation->getMorphClass());

        // A superseded contract is retained as evidence but is not what a
        // customer should be reading.
        $this->assertCount(1, $listed);
        $this->assertSame(2, $listed->first()->version);
    }

    public function test_a_draft_invoices_pdf_is_not_listed(): void
    {
        $customer = $this->customer();
        $staff = $this->staff();
        $quotation = $this->sentQuotationFor($customer);
        app(TransitionQuotation::class)->respond($customer, $quotation, true);
        $invoice = app(ConvertQuotationToInvoice::class)->execute($staff, $quotation->fresh());

        $this->assertSame(InvoiceStatus::Draft, $invoice->status);

        $listed = app(CustomerDocumentQuery::class)->forCustomer($customer)
            ->where('documentable_type', $invoice->getMorphClass());

        $this->assertCount(0, $listed);
    }

    public function test_the_documents_page_renders(): void
    {
        $customer = $this->customer();
        $this->issuedInvoiceFor($customer);

        $this->actingAs($customer)
            ->get(route('portal.documents'))
            ->assertOk()
            ->assertSee('My documents')
            ->assertSee('Invoice');
    }

    public function test_a_customer_with_no_documents_gets_an_honest_empty_state(): void
    {
        $this->actingAs($this->customer())
            ->get(route('portal.documents'))
            ->assertOk()
            ->assertSee('No documents yet');
    }

    // ---- The notification inbox ------------------------------------------

    public function test_the_database_channel_is_now_readable(): void
    {
        $customer = $this->customer();

        // Before F13 this row was written by every notification and rendered
        // nowhere — a feature that existed only in the database.
        $customer->notify(new TourBookingConfirmedNotification(
            bookingReference: 'TB-TEST-1',
            tourName: 'Bwindi gorilla trek',
            departureStartsAt: now()->addDays(10)->toIso8601String(),
            totalMinor: 5_000_000,
            currency: 'UGX',
        ));

        $this->assertSame(1, $customer->notifications()->count());

        $this->actingAs($customer)
            ->get(route('portal.notifications.index'))
            ->assertOk()
            ->assertSee('Bwindi gorilla trek')
            ->assertSee('New');
    }

    public function test_marking_one_read_follows_its_link(): void
    {
        $customer = $this->customer();
        $customer->notify(new TourBookingConfirmedNotification(
            bookingReference: 'TB-TEST-2',
            tourName: 'Murchison Falls',
            departureStartsAt: now()->addDays(5)->toIso8601String(),
            totalMinor: 5_000_000,
            currency: 'UGX',
        ));

        $notification = $customer->notifications()->sole();

        $this->actingAs($customer)
            ->post(route('portal.notifications.read', $notification->id))
            ->assertRedirect();

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_a_customer_cannot_open_another_customers_message(): void
    {
        $owner = $this->customer();
        $stranger = $this->customer();

        $owner->notify(new TourBookingConfirmedNotification(
            bookingReference: 'TB-TEST-3',
            tourName: 'Queen Elizabeth',
            departureStartsAt: now()->addDays(5)->toIso8601String(),
            totalMinor: 5_000_000,
            currency: 'UGX',
        ));

        $notification = $owner->notifications()->sole();

        // The lookup is scoped through the notifiable relationship.
        $this->actingAs($stranger)
            ->post(route('portal.notifications.read', $notification->id))
            ->assertNotFound();

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_marking_all_read_clears_the_badge(): void
    {
        $customer = $this->customer();

        for ($i = 0; $i < 3; $i++) {
            $customer->notify(new TourBookingConfirmedNotification(
                bookingReference: 'TB-BULK-'.$i,
                tourName: 'Trip '.$i,
                departureStartsAt: now()->addDays(5)->toIso8601String(),
                totalMinor: 5_000_000,
                currency: 'UGX',
            ));
        }

        $this->assertSame(3, $customer->unreadNotifications()->count());

        $this->actingAs($customer)
            ->post(route('portal.notifications.read-all'))
            ->assertRedirect(route('portal.notifications.index'));

        $this->assertSame(0, $customer->fresh()->unreadNotifications()->count());
    }

    public function test_the_unread_count_shows_on_the_portal_home(): void
    {
        $customer = $this->customer();
        $customer->notify(new TourBookingConfirmedNotification(
            bookingReference: 'TB-TEST-4',
            tourName: 'Kidepo Valley',
            departureStartsAt: now()->addDays(9)->toIso8601String(),
            totalMinor: 5_000_000,
            currency: 'UGX',
        ));

        $this->actingAs($customer)
            ->get(route('portal.index'))
            ->assertOk()
            ->assertSee('1 unread message');
    }

    public function test_an_empty_inbox_says_so(): void
    {
        $this->actingAs($this->customer())
            ->get(route('portal.notifications.index'))
            ->assertOk()
            ->assertSee('No messages');
    }
}
