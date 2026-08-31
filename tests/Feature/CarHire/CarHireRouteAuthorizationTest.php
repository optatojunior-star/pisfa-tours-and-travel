<?php

namespace Tests\Feature\CarHire;

use App\Enums\HireMode;
use App\Enums\SelfDriveApplicationStatus;
use App\Enums\UserRole;
use App\Models\AuditLog;
use App\Models\CarHireDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\CarHire\Concerns\BuildsCarHireFixtures;
use Tests\TestCase;

class CarHireRouteAuthorizationTest extends TestCase
{
    use BuildsCarHireFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
    }

    public function test_guests_unverified_customers_and_wrong_roles_are_stopped_at_route_boundaries(): void
    {
        [$vehicle] = $this->bookableVehicle();

        foreach ([
            route('car-hire-bookings.create', $vehicle),
            route('portal.car-hire-bookings.index'),
            route('admin.vehicles.index'),
            route('admin.car-hire-bookings.index'),
        ] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }

        $unverified = $this->customer(['email_verified_at' => null]);
        $this->actingAs($unverified)
            ->get(route('car-hire-bookings.create', $vehicle))
            ->assertRedirect(route('verification.notice'));
        $this->actingAs($unverified)
            ->get(route('portal.car-hire-bookings.index'))
            ->assertRedirect(route('verification.notice'));

        foreach ([UserRole::Staff, UserRole::Manager, UserRole::Driver, UserRole::SuperAdmin] as $role) {
            $actor = $this->user($role, ['two_factor_required' => false]);
            $this->actingAs($actor)
                ->get(route('car-hire-bookings.create', $vehicle))
                ->assertForbidden();
            $this->actingAs($actor)
                ->get(route('portal.car-hire-bookings.index'))
                ->assertForbidden();
        }

        foreach ([$this->customer(), $this->driver()] as $actor) {
            $this->actingAs($actor)->get(route('admin.vehicles.index'))->assertForbidden();
            $this->actingAs($actor)->get(route('admin.car-hire-bookings.index'))->assertForbidden();
        }

        $staff = $this->operationsUser();
        $this->actingAs($staff)
            ->get(route('admin.vehicles.index'))
            ->assertOk()
            ->assertSee('Vehicles and rates');
        $this->actingAs($staff)
            ->get(route('admin.car-hire-bookings.index'))
            ->assertOk()
            ->assertSee('Hire bookings');
    }

    public function test_customer_owned_binding_returns_404_for_every_foreign_reference(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        [$vehicle, $rate] = $this->bookableVehicle();
        $owned = $this->persistedBooking($owner, $vehicle, $rate);
        $foreign = $this->persistedBooking($other, $vehicle, $rate, attributes: [
            'pickup_at' => now()->addDays(20),
            'return_at' => now()->addDays(23),
        ]);

        $this->actingAs($owner)
            ->get(route('portal.car-hire-bookings.show', $owned))
            ->assertOk()
            ->assertSee($owned->reference);
        $this->actingAs($owner)
            ->get(route('portal.car-hire-bookings.show', $foreign))
            ->assertNotFound();
        $this->actingAs($owner)
            ->patch(route('portal.car-hire-bookings.cancel', $foreign), [
                'reason' => 'This foreign request must remain unchanged.',
                'confirm_cancellation' => '1',
            ])
            ->assertNotFound();

        $this->assertSame('pending', $foreign->fresh()->status->value);
    }

    public function test_private_documents_require_ownership_match_nesting_and_audited_downloads(): void
    {
        Storage::fake('local');
        $owner = $this->customer();
        $other = $this->customer();
        [$vehicle, $rate] = $this->bookableVehicle();
        $booking = $this->persistedBooking($owner, $vehicle, $rate, mode: HireMode::SelfDrive);
        $secondOwnedBooking = $this->persistedBooking(
            $owner,
            $vehicle,
            $rate,
            mode: HireMode::SelfDrive,
            attributes: ['pickup_at' => now()->addDays(20), 'return_at' => now()->addDays(23)],
        );
        $foreignBooking = $this->persistedBooking(
            $other,
            $vehicle,
            $rate,
            mode: HireMode::SelfDrive,
            attributes: ['pickup_at' => now()->addDays(30), 'return_at' => now()->addDays(33)],
        );
        $path = 'car-hire/documents/private-proof.pdf';
        $content = '%PDF-1.4 private identity proof';
        Storage::disk('local')->put($path, $content);
        $document = CarHireDocument::query()->create([
            'car_hire_booking_id' => $booking->getKey(),
            'document_type' => 'national_id',
            'disk' => 'local',
            'path' => $path,
            'original_name' => 'identity-proof.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($content),
            'content_sha256' => hash('sha256', $content),
            'uploaded_by_user_id' => $owner->getKey(),
        ]);

        $this->actingAs($owner)
            ->get(route('portal.car-hire-bookings.documents.download', [$booking, $document]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($owner)
            ->get(route('portal.car-hire-bookings.documents.download', [$secondOwnedBooking, $document]))
            ->assertNotFound();
        $this->actingAs($other)
            ->get(route('portal.car-hire-bookings.documents.download', [$booking, $document]))
            ->assertNotFound();
        $this->actingAs($other)
            ->get(route('portal.car-hire-bookings.documents.download', [$foreignBooking, $document]))
            ->assertNotFound();

        $staff = $this->operationsUser();
        $this->actingAs($staff)
            ->get(route('admin.car-hire-bookings.documents.download', [$booking, $document]))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->actingAs($this->driver())
            ->get(route('admin.car-hire-bookings.documents.download', [$booking, $document]))
            ->assertForbidden();

        $this->assertDatabaseCount('audit_logs', 2);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'car_hire.document_downloaded',
            'auditable_id' => $document->getKey(),
            'user_id' => $staff->getKey(),
        ]);

        $auditPayload = json_encode(
            AuditLog::query()->latest('id')->value('new_values'),
            JSON_THROW_ON_ERROR,
        );
        $this->assertStringNotContainsString($path, $auditPayload);
        $this->assertStringNotContainsString(hash('sha256', $content), $auditPayload);
    }

    public function test_contract_acceptance_is_owner_scoped_version_scoped_and_explicit(): void
    {
        $owner = $this->customer();
        [$vehicle, $rate] = $this->bookableVehicle();
        $booking = $this->persistedBooking($owner, $vehicle, $rate);
        $otherBooking = $this->persistedBooking($owner, $vehicle, $rate, attributes: [
            'pickup_at' => now()->addDays(20),
            'return_at' => now()->addDays(23),
        ]);
        $contract = $this->contractFor($booking);
        $otherContract = $this->contractFor($otherBooking);

        $this->actingAs($owner)
            ->get(route('portal.car-hire-bookings.contracts.show', [$booking, $otherContract]))
            ->assertNotFound();

        $url = route('portal.car-hire-bookings.contracts.show', [$booking, $contract]);
        $this->actingAs($owner)
            ->from($url)
            ->patch(route('portal.car-hire-bookings.contracts.accept', [$booking, $contract]))
            ->assertRedirect($url)
            ->assertSessionHasErrors('accept_contract');
        $this->assertNull($contract->fresh()->accepted_at);

        $this->actingAs($owner)
            ->patch(route('portal.car-hire-bookings.contracts.accept', [$booking, $contract]), [
                'accept_contract' => '1',
            ])
            ->assertRedirect($url)
            ->assertSessionHasNoErrors();

        $this->assertSame($owner->getKey(), $contract->fresh()->accepted_by_user_id);
        $this->assertNotNull($contract->fresh()->accepted_at);
    }

    public function test_administration_masks_identity_numbers_and_requires_the_originals_confirmation_phrase(): void
    {
        $customer = $this->customer();
        [$vehicle, $rate] = $this->bookableVehicle();
        $booking = $this->persistedBooking($customer, $vehicle, $rate, mode: HireMode::SelfDrive);
        $application = $booking->selfDriveApplication;
        $application->forceFill([
            'status' => SelfDriveApplicationStatus::Approved,
            'national_id_number' => 'CM1234567890',
            'driving_permit_number' => 'DL9876543210',
        ])->save();
        $staff = $this->operationsUser();

        $this->actingAs($staff)
            ->get(route('admin.car-hire-bookings.show', $booking))
            ->assertOk()
            ->assertSee('•••• 7890')
            ->assertSee('•••• 3210')
            ->assertDontSee('CM1234567890')
            ->assertDontSee('DL9876543210');

        $this->actingAs($staff)
            ->from(route('admin.car-hire-bookings.show', $booking))
            ->patch(route('admin.car-hire-bookings.self-drive.verify-originals', $booking), [
                'originals_verified' => '1',
                'confirmation_phrase' => 'verify originals',
            ])
            ->assertRedirect(route('admin.car-hire-bookings.show', $booking))
            ->assertSessionHasErrors('confirmation_phrase');

        $this->assertNull($application->fresh()->originals_verified_at);
    }
}
