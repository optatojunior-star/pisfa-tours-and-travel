<?php

namespace Tests\Feature\AirportTransfers;

use App\Actions\AirportTransfers\AssignAirportTransferResources;
use App\Enums\DocumentCategory;
use App\Enums\VehicleOperationalStatus;
use App\Http\Controllers\Concerns\HandlesImageUploads;
use App\Models\AirportTransferBooking;
use App\Models\DriverProfile;
use App\Models\User;
use App\Models\Vehicle;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\Feature\AirportTransfers\Concerns\BuildsAirportTransferFixtures;
use Tests\TestCase;

/**
 * What the customer is told about the person meeting them.
 *
 * "Where is my driver" is the call this business takes most often, and the
 * confirmation used to answer it with a name and, if the office had bothered,
 * a phone number in plain text. A face, a number plate and a number you can
 * press are what turn that call into no call at all.
 */
class TransferConfirmationDetailsTest extends TestCase
{
    use BuildsAirportTransferFixtures;
    use HandlesImageUploads;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_the_customer_sees_the_driver_photograph_plate_and_a_tappable_number(): void
    {
        [$booking, $customer] = $this->assignedBooking();

        $this->actingAs($customer)
            ->get(route('portal.airport-transfer-bookings.show', $booking))
            ->assertOk()
            ->assertSee('Who is meeting you')
            ->assertSee('Amos Wanyama')
            // The plate is what you check in a car park; it was never shown.
            ->assertSee('UBK 404Z')
            ->assertSee('tel:+256700111222', false)
            ->assertSee('/documents/', false);
    }

    /**
     * A guest keeps only the signed acknowledgement link. It promised that the
     * team would confirm a vehicle and driver, and then showed neither.
     */
    public function test_a_guest_sees_the_same_details_through_their_signed_link(): void
    {
        [$booking] = $this->assignedBooking(guest: true);

        $this->get($this->guestUrl($booking))
            ->assertOk()
            ->assertSee('Who is meeting you')
            ->assertSee('Amos Wanyama')
            ->assertSee('UBK 404Z');
    }

    public function test_an_unassigned_transfer_says_so_instead_of_showing_blanks(): void
    {
        [$airport, $location] = $this->bookableRoute();
        $customer = $this->customer();
        $booking = $this->createBooking($customer, $airport, $location);

        $this->actingAs($customer)
            ->get(route('portal.airport-transfer-bookings.show', $booking))
            ->assertOk()
            ->assertSee('A vehicle and driver are assigned once our team confirms');
    }

    /** A driver with no photograph must not break the page or show a broken image. */
    public function test_a_driver_without_a_photograph_falls_back_to_their_initial(): void
    {
        [$booking, $customer] = $this->assignedBooking(withPhotograph: false);

        $this->actingAs($customer)
            ->get(route('portal.airport-transfer-bookings.show', $booking))
            ->assertOk()
            ->assertSee('Amos Wanyama')
            ->assertDontSee('/documents/', false);
    }

    /**
     * The photograph link is minted per render and scoped to the viewer's own
     * booking. One customer must not be able to reach another's driver by
     * opening a confirmation that is not theirs.
     */
    public function test_another_customer_cannot_open_the_confirmation_at_all(): void
    {
        [$booking] = $this->assignedBooking();

        $this->actingAs($this->customer())
            ->get(route('portal.airport-transfer-bookings.show', $booking))
            ->assertNotFound();
    }

    /** @return array{AirportTransferBooking, User} */
    private function assignedBooking(bool $guest = false, bool $withPhotograph = true): array
    {
        [$airport, $location] = $this->bookableRoute();
        $staff = $this->operationsUser();
        $customer = $this->customer();

        $driver = $this->driver(['name' => 'Amos Wanyama', 'phone' => '+256700111222']);
        $profile = DriverProfile::query()->create([
            'user_id' => $driver->getKey(),
            'licence_number' => 'UG-DL-55443322',
            'licence_expires_at' => now()->addYear()->toDateString(),
            'is_available' => true,
        ]);

        if ($withPhotograph) {
            $request = Request::create('/', 'POST', [], [], [
                'images' => [UploadedFile::fake()->image('amos.jpg', 600, 600)],
            ]);
            $request->setUserResolver(static fn (): User => $driver);

            $this->storeUploadedImages($request, $profile, DocumentCategory::DriverPhoto);
        }

        $vehicle = Vehicle::factory()->create([
            'operational_status' => VehicleOperationalStatus::Available,
            'vehicle_type' => 'sedan',
            'seating_capacity' => 6,
            'luggage_capacity' => 6,
            'registration_plate' => 'UBK 404Z',
        ]);

        $booking = $this->createBooking($guest ? null : $customer, $airport, $location);
        app(AssignAirportTransferResources::class)->execute($staff, $booking, $vehicle, $driver);

        return [$booking->refresh(), $customer];
    }

    private function guestUrl(AirportTransferBooking $booking): string
    {
        return URL::temporarySignedRoute(
            'airport-transfer-bookings.guest.show',
            now()->addHours(2),
            ['airportTransferBooking' => $booking->reference],
        );
    }
}
