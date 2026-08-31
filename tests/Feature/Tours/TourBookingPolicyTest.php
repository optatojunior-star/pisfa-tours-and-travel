<?php

namespace Tests\Feature\Tours;

use App\Enums\AccountStatus;
use App\Enums\TourBookingStatus;
use App\Enums\UserRole;
use App\Models\TourBooking;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\Feature\Tours\Concerns\BuildsTourFixtures;
use Tests\TestCase;

class TourBookingPolicyTest extends TestCase
{
    use BuildsTourFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo('2026-08-20 09:00:00');
    }

    public function test_view_any_is_limited_to_active_administration_roles(): void
    {
        foreach ([UserRole::Staff, UserRole::Manager, UserRole::SuperAdmin] as $role) {
            $this->assertTrue(
                Gate::forUser($this->operationsUser($role))->allows('viewAny', TourBooking::class),
                "An active {$role->value} should be able to list tour bookings.",
            );
        }

        foreach ([UserRole::Customer, UserRole::Driver] as $role) {
            $this->assertFalse(
                Gate::forUser($this->user($role))->allows('viewAny', TourBooking::class),
                "An active {$role->value} must not list all tour bookings.",
            );
        }

        $inactiveStaff = $this->operationsUser(UserRole::Staff, [
            'status' => AccountStatus::Inactive,
        ]);
        $this->assertFalse(Gate::forUser($inactiveStaff)->allows('viewAny', TourBooking::class));
    }

    public function test_inactive_customer_and_driver_cannot_view_even_owned_or_assigned_bookings(): void
    {
        $departure = $this->bookableDeparture();
        $inactiveCustomer = $this->customer(['status' => AccountStatus::Inactive]);
        $ownedBooking = $this->persistedBooking($inactiveCustomer, $departure);
        $inactiveDriver = $this->driver(['status' => AccountStatus::Inactive]);
        $assignedBooking = $this->persistedBooking(
            $this->customer(),
            $departure,
            TourBookingStatus::Confirmed,
            attributes: ['assigned_driver_user_id' => $inactiveDriver->getKey()],
        );

        $this->assertFalse(Gate::forUser($inactiveCustomer)->allows('view', $ownedBooking));
        $this->assertFalse(Gate::forUser($inactiveDriver)->allows('view', $assignedBooking));
    }
}
