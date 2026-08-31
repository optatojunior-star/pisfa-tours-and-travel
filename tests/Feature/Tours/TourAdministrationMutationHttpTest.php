<?php

namespace Tests\Feature\Tours;

use App\Enums\TourBookingStatus;
use App\Enums\TourDepartureStatus;
use App\Enums\TourPackageStatus;
use App\Models\TourCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class TourAdministrationMutationHttpTest extends TestCase
{
    use BuildsTourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
    }

    public function test_every_planned_tour_route_is_registered_with_the_expected_http_method(): void
    {
        $expected = [
            'tours.index' => 'GET',
            'tours.show' => 'GET',
            'tour-bookings.create' => 'GET',
            'tour-bookings.store' => 'POST',
            'portal.bookings.index' => 'GET',
            'portal.bookings.show' => 'GET',
            'portal.bookings.cancel' => 'PATCH',
            'admin.tour-categories.index' => 'GET',
            'admin.tour-categories.store' => 'POST',
            'admin.tour-categories.update' => 'PATCH',
            'admin.tour-categories.toggle' => 'PATCH',
            'admin.tours.index' => 'GET',
            'admin.tours.create' => 'GET',
            'admin.tours.store' => 'POST',
            'admin.tours.show' => 'GET',
            'admin.tours.edit' => 'GET',
            'admin.tours.update' => 'PATCH',
            'admin.tours.publish' => 'PATCH',
            'admin.tours.archive' => 'PATCH',
            'admin.tours.restore' => 'PATCH',
            'admin.tour-departures.store' => 'POST',
            'admin.tour-departures.update' => 'PATCH',
            'admin.tour-departures.status' => 'PATCH',
            'admin.tour-bookings.index' => 'GET',
            'admin.tour-bookings.show' => 'GET',
            'admin.tour-bookings.transition' => 'PATCH',
            'admin.tour-bookings.assign' => 'PATCH',
        ];

        foreach ($expected as $name => $method) {
            $route = Route::getRoutes()->getByName($name);
            $this->assertNotNull($route, "Missing named route {$name}.");
            $this->assertContains($method, $route->methods(), "Route {$name} does not accept {$method}.");
        }
    }

    public function test_staff_can_create_update_and_deactivate_an_unused_category_with_audits(): void
    {
        $staff = $this->operationsUser();

        $this->actingAs($staff)
            ->post(route('admin.tour-categories.store'), [
                'name' => 'Lake Adventures',
                'slug' => 'lake-adventures',
                'description' => 'Water-based journeys.',
                'sort_order' => 25,
            ])
            ->assertRedirect(route('admin.tour-categories.index'))
            ->assertSessionHasNoErrors();

        $category = TourCategory::query()->where('slug', 'lake-adventures')->sole();
        $this->actingAs($staff)
            ->patch(route('admin.tour-categories.update', $category), [
                'name' => 'Great Lakes Adventures',
                'slug' => 'great-lakes-adventures',
                'description' => 'Updated water-based journeys.',
                'sort_order' => 30,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $category->refresh();
        $this->actingAs($staff)
            ->patch(route('admin.tour-categories.toggle', $category), ['is_active' => false])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame('Great Lakes Adventures', $category->fresh()->name);
        $this->assertFalse($category->fresh()->is_active);
        foreach (['tour_category.created', 'tour_category.updated', 'tour_category.status_changed'] as $event) {
            $this->assertDatabaseHas('audit_logs', [
                'event' => $event,
                'auditable_id' => $category->getKey(),
                'user_id' => $staff->getKey(),
            ]);
        }
    }

    public function test_admin_booking_transition_and_assignment_endpoints_invoke_domain_actions(): void
    {
        $staff = $this->operationsUser();
        $driver = $this->driver();
        $booking = $this->persistedBooking($this->customer(), $this->bookableDeparture());

        $this->actingAs($staff)
            ->patch(route('admin.tour-bookings.transition', $booking), [
                'status' => TourBookingStatus::Confirmed->value,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->actingAs($staff)
            ->patch(route('admin.tour-bookings.assign', $booking), [
                'driver_user_id' => $driver->getKey(),
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $booking->refresh();
        $this->assertSame(TourBookingStatus::Confirmed, $booking->status);
        $this->assertSame($driver->getKey(), $booking->assigned_driver_user_id);
        $this->assertDatabaseHas('tour_assignments', [
            'tour_booking_id' => $booking->getKey(),
            'driver_user_id' => $driver->getKey(),
            'assigned_by_user_id' => $staff->getKey(),
            'unassigned_at' => null,
        ]);
    }

    public function test_departure_status_endpoint_enforces_transition_rules_and_audits(): void
    {
        $staff = $this->operationsUser();
        $departure = $this->bookableDeparture();

        $this->actingAs($staff)
            ->patch(route('admin.tour-departures.status', [$departure->tourPackage, $departure]), [
                'status' => TourDepartureStatus::Closed->value,
                'reason' => 'Temporarily pausing sales.',
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(TourDepartureStatus::Closed, $departure->fresh()->status);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'tour_departure.status_changed',
            'auditable_id' => $departure->getKey(),
            'user_id' => $staff->getKey(),
        ]);

        $departure->update(['status' => TourDepartureStatus::Cancelled]);

        try {
            $this->actingAs($staff)
                ->patch(route('admin.tour-departures.status', [$departure->tourPackage, $departure]), [
                    'status' => TourDepartureStatus::Scheduled->value,
                ])
                ->assertRedirect()
                ->assertSessionHasErrors('status');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('status', $exception->errors());
        }

        $this->assertSame(TourDepartureStatus::Cancelled, $departure->fresh()->status);
    }

    public function test_archiving_package_preserves_existing_booking_and_records_audit(): void
    {
        $staff = $this->operationsUser();
        $package = $this->publishedTour();
        $booking = $this->persistedBooking(
            $this->customer(),
            $this->bookableDeparture($package),
            TourBookingStatus::Confirmed,
        );

        $this->actingAs($staff)
            ->patch(route('admin.tours.archive', $package))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame(TourPackageStatus::Archived, $package->fresh()->status);
        $this->assertDatabaseHas('tour_bookings', ['id' => $booking->getKey()]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'tour_package.archived',
            'auditable_id' => $package->getKey(),
            'user_id' => $staff->getKey(),
        ]);
    }
}
