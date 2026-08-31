<?php

namespace Tests\Feature\VehicleImports;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Enums\VehicleImportBodyType;
use App\Enums\VehicleImportDriveType;
use App\Enums\VehicleImportEventType;
use App\Enums\VehicleImportFuelType;
use App\Enums\VehicleImportStatus;
use App\Enums\VehicleImportSteering;
use App\Enums\VehicleImportTransmission;
use App\Models\User;
use App\Models\VehicleImportOrder;
use App\Notifications\VehicleImports\VehicleImportReceivedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class VehicleImportHttpTest extends TestCase
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
            'two_factor_required' => false,
        ], $attributes));
    }

    private function customer(array $attributes = []): User
    {
        return $this->user(UserRole::Customer, $attributes);
    }

    /** @return array<string, mixed> */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'idempotency_key' => (string) Str::uuid(),
            'make' => 'Toyota',
            'model' => 'Harrier',
            'year_from' => 2017,
            'year_to' => 2020,
            'body_type' => VehicleImportBodyType::Suv->value,
            'fuel_type' => VehicleImportFuelType::Petrol->value,
            'transmission' => VehicleImportTransmission::Automatic->value,
            'drive_type' => VehicleImportDriveType::FourWheelDrive->value,
            'steering' => VehicleImportSteering::RightHand->value,
            'origin_country' => 'JP',
            'units' => 1,
            'purpose' => 'personal',
            'budget' => '90000000',
            'budget_currency' => 'UGX',
            'contact_name' => 'Guest Importer',
            'contact_email' => 'importer@example.test',
            'contact_phone' => '+256701234567',
            'acknowledge_request' => '1',
        ], $overrides);
    }

    public function test_the_public_form_renders(): void
    {
        $this->get(route('vehicle-imports.create'))
            ->assertOk()
            ->assertSee('Import the vehicle you actually want')
            ->assertSee('Request a quotation');
    }

    public function test_a_guest_request_lands_on_an_unguessable_tracking_page(): void
    {
        $response = $this->post(route('vehicle-imports.store'), $this->payload());

        $order = VehicleImportOrder::query()->sole();
        $this->assertNull($order->customer_id);
        $this->assertSame(VehicleImportStatus::Inquiry, $order->status);
        $this->assertSame(64, strlen($order->tracking_token));
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/', $order->tracking_token);

        $response->assertRedirect(route('vehicle-imports.track', ['token' => $order->tracking_token]));

        $this->get(route('vehicle-imports.track', ['token' => $order->tracking_token]))
            ->assertOk()
            ->assertSee($order->reference)
            ->assertSee('Keep this tracking link private');

        Notification::assertSentOnDemand(VehicleImportReceivedNotification::class);
    }

    public function test_a_wrong_tracking_token_is_not_found(): void
    {
        VehicleImportOrder::factory()->create();

        $this->get(route('vehicle-imports.track', ['token' => str_repeat('a', 64)]))
            ->assertNotFound();

        // A short token never even reaches a query.
        $this->get('/track/import/abc')->assertNotFound();
    }

    public function test_the_tracking_page_never_exposes_the_token_of_another_import(): void
    {
        $mine = VehicleImportOrder::factory()->create();
        $theirs = VehicleImportOrder::factory()->create();

        $this->get(route('vehicle-imports.track', ['token' => $mine->tracking_token]))
            ->assertOk()
            ->assertSee($mine->reference)
            ->assertDontSee($theirs->reference)
            ->assertDontSee($theirs->tracking_token);
    }

    public function test_a_signed_in_customer_request_uses_account_details_and_lands_in_the_portal(): void
    {
        $customer = $this->customer(['name' => 'Grace Nakato', 'email' => 'grace@example.test']);

        $response = $this->actingAs($customer)->post(route('vehicle-imports.store'), $this->payload([
            'contact_name' => 'Someone Else',
            'contact_email' => 'spoofed@example.test',
        ]));

        $order = VehicleImportOrder::query()->sole();
        $this->assertSame($customer->getKey(), $order->customer_id);
        $this->assertSame('grace@example.test', $order->contact_email);
        $this->assertSame('Grace Nakato', $order->contact_name);

        $response->assertRedirect(route('portal.vehicle-imports.show', $order));
    }

    public function test_repeating_an_idempotency_key_returns_the_same_request(): void
    {
        $payload = $this->payload();

        $this->post(route('vehicle-imports.store'), $payload)->assertRedirect();
        $this->post(route('vehicle-imports.store'), $payload)->assertRedirect();

        $this->assertDatabaseCount('vehicle_import_orders', 1);
    }

    public function test_a_budget_below_the_minimum_is_refused(): void
    {
        config(['vehicle_imports.minimum_budget.UGX' => 5_000_000]);

        $this->post(route('vehicle-imports.store'), $this->payload(['budget' => '100000']))
            ->assertSessionHasErrors('budget');

        $this->assertDatabaseCount('vehicle_import_orders', 0);
    }

    public function test_a_year_range_running_backwards_is_refused(): void
    {
        $this->post(route('vehicle-imports.store'), $this->payload([
            'year_from' => 2021,
            'year_to' => 2018,
        ]))->assertSessionHasErrors('year_to');

        $this->assertDatabaseCount('vehicle_import_orders', 0);
    }

    public function test_the_acknowledgement_is_required(): void
    {
        $payload = $this->payload();
        unset($payload['acknowledge_request']);

        $this->post(route('vehicle-imports.store'), $payload)
            ->assertSessionHasErrors('acknowledge_request');

        $this->assertDatabaseCount('vehicle_import_orders', 0);
    }

    public function test_the_portal_shows_only_the_signed_in_customers_imports(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        $mine = VehicleImportOrder::factory()->forCustomer($owner)->create();
        $theirs = VehicleImportOrder::factory()->forCustomer($other)->create();

        $this->actingAs($owner)
            ->get(route('portal.vehicle-imports.index'))
            ->assertOk()
            ->assertSee($mine->reference)
            ->assertDontSee($theirs->reference);

        $this->actingAs($owner)
            ->get(route('portal.vehicle-imports.show', $theirs))
            ->assertNotFound();
    }

    public function test_a_guest_import_is_not_reachable_from_the_portal(): void
    {
        $guestOrder = VehicleImportOrder::factory()->create();

        $this->actingAs($this->customer())
            ->get(route('portal.vehicle-imports.show', $guestOrder))
            ->assertNotFound();
    }

    public function test_the_portal_shows_a_pay_now_button_once_quoted(): void
    {
        $customer = $this->customer();
        $order = VehicleImportOrder::factory()->forCustomer($customer)->quoted()->create();
        config(['payments.providers.bank_transfer.enabled' => true]);

        $this->actingAs($customer)
            ->get(route('portal.vehicle-imports.show', $order))
            ->assertOk()
            ->assertSee('Pay now')
            // Deposit first, not the full price.
            ->assertSee('UGX 30,000,000')
            ->assertSee(route('payments.checkout', ['vehicle-imports', $order->reference]));
    }

    public function test_an_unquoted_import_offers_no_payment(): void
    {
        $customer = $this->customer();
        $order = VehicleImportOrder::factory()->forCustomer($customer)->create();

        $this->actingAs($customer)
            ->get(route('portal.vehicle-imports.show', $order))
            ->assertOk()
            ->assertSee('not currently accepting payment')
            ->assertDontSee('Pay now');
    }

    public function test_operations_staff_reach_the_console_and_customers_do_not(): void
    {
        $order = VehicleImportOrder::factory()->create();

        $this->actingAs($this->user(UserRole::Staff))
            ->get(route('admin.vehicle-imports.index'))
            ->assertOk()
            ->assertSee($order->reference);

        foreach ([$this->customer(), $this->user(UserRole::Driver)] as $actor) {
            $this->actingAs($actor)->get(route('admin.vehicle-imports.index'))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.vehicle-imports.show', $order))->assertForbidden();
        }
    }

    public function test_a_guest_is_sent_to_login_for_the_console(): void
    {
        // Kept separate: actingAs persists for the rest of a test, so asserting
        // the guest case after a loop of authenticated actors would silently
        // test the last actor instead.
        VehicleImportOrder::factory()->create();

        $this->get(route('admin.vehicle-imports.index'))->assertRedirect(route('login'));
        $this->get(route('portal.vehicle-imports.index'))->assertRedirect(route('login'));
    }

    public function test_staff_publish_a_quotation_through_the_console(): void
    {
        $staff = $this->user(UserRole::Staff);
        $order = VehicleImportOrder::factory()->create();

        $this->actingAs($staff)
            ->post(route('admin.vehicle-imports.quote', $order), [
                'total_price' => '110000000',
                'deposit' => '25000000',
                'currency' => 'UGX',
                'estimated_arrival_on' => now()->addDays(50)->toDateString(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $order->refresh();
        $this->assertSame(110_000_000, $order->total_price_minor);
        $this->assertSame(25_000_000, $order->deposit_minor);
        $this->assertDatabaseHas('audit_logs', ['event' => 'vehicle_import.quoted']);
    }

    public function test_a_deposit_over_the_total_is_rejected_by_the_console(): void
    {
        $staff = $this->user(UserRole::Staff);
        $order = VehicleImportOrder::factory()->create();

        $this->actingAs($staff)
            ->from(route('admin.vehicle-imports.show', $order))
            ->post(route('admin.vehicle-imports.quote', $order), [
                'total_price' => '50000000',
                'deposit' => '60000000',
                'currency' => 'UGX',
                'estimated_arrival_on' => now()->addDays(30)->toDateString(),
            ])
            ->assertSessionHasErrors('deposit');

        $this->assertNull($order->fresh()->total_price_minor);
    }

    public function test_the_customer_timeline_hides_internal_events(): void
    {
        $customer = $this->customer();
        $staff = $this->user(UserRole::Staff);
        $order = VehicleImportOrder::factory()->forCustomer($customer)->create();

        $order->recordEvent(
            VehicleImportEventType::StatusChanged,
            'Internal sourcing margin reviewed.',
            [],
            $staff,
            customerVisible: false,
        );
        $order->recordEvent(
            VehicleImportEventType::StatusChanged,
            'We are reviewing your requirements.',
            [],
            $staff,
        );

        $this->actingAs($customer)
            ->get(route('portal.vehicle-imports.show', $order))
            ->assertOk()
            ->assertSee('We are reviewing your requirements.')
            ->assertDontSee('Internal sourcing margin reviewed.');

        // The same entry is visible to operations.
        $this->actingAs($staff)
            ->get(route('admin.vehicle-imports.show', $order))
            ->assertOk()
            ->assertSee('Internal sourcing margin reviewed.');
    }
}
