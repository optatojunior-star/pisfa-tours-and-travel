<?php

namespace Tests\Feature\FlightInquiries;

use App\Actions\FlightInquiries\AssignFlightInquiry;
use App\Actions\FlightInquiries\TransitionFlightInquiry;
use App\Enums\AccountStatus;
use App\Enums\FlightInquiryEntryType;
use App\Enums\FlightInquiryStatus;
use App\Models\FlightInquiry;
use App\Notifications\FlightInquiries\FlightInquiryAssignedNotification;
use App\Notifications\FlightInquiries\FlightInquiryStatusNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Feature\FlightInquiries\Concerns\BuildsFlightInquiryFixtures;
use Tests\TestCase;

class FlightInquiryWorkflowTest extends TestCase
{
    use BuildsFlightInquiryFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->travelTo('2026-08-20 09:00:00');
        Notification::fake();
    }

    public function test_the_console_filters_by_queue_status_and_consultant(): void
    {
        $staff = $this->operationsUser();
        $other = $this->operationsUser();

        $unassigned = $this->createInquiry();
        $mine = FlightInquiry::factory()->create([
            'assigned_to_user_id' => $staff->getKey(),
            'assigned_at' => now(),
        ]);
        $closed = FlightInquiry::factory()->withStatus(FlightInquiryStatus::Closed)->create([
            'assigned_to_user_id' => $other->getKey(),
        ]);

        $this->actingAs($staff)
            ->get(route('admin.flight-inquiries.index', ['queue' => 'unassigned']))
            ->assertOk()
            ->assertSee($unassigned->reference)
            ->assertDontSee($mine->reference);

        $this->actingAs($staff)
            ->get(route('admin.flight-inquiries.index', ['queue' => 'mine']))
            ->assertOk()
            ->assertSee($mine->reference)
            ->assertDontSee($unassigned->reference);

        $this->actingAs($staff)
            ->get(route('admin.flight-inquiries.index', ['status' => FlightInquiryStatus::Closed->value]))
            ->assertOk()
            ->assertSee($closed->reference)
            ->assertDontSee($unassigned->reference);

        $this->actingAs($staff)
            ->get(route('admin.flight-inquiries.index', ['q' => $unassigned->destination]))
            ->assertOk()
            ->assertSee($unassigned->reference);
    }

    public function test_assignment_notifies_the_consultant_and_records_history(): void
    {
        $staff = $this->operationsUser();
        $consultant = $this->operationsUser(['name' => 'Sarah Nabirye']);
        $inquiry = $this->createInquiry();

        $this->actingAs($staff)
            ->patch(route('admin.flight-inquiries.assignment', $inquiry), [
                'assigned_to_user_id' => $consultant->getKey(),
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $inquiry->refresh();
        $this->assertSame($consultant->getKey(), $inquiry->assigned_to_user_id);
        $this->assertNotNull($inquiry->assigned_at);

        Notification::assertSentTo($consultant, FlightInquiryAssignedNotification::class);
        $this->assertDatabaseHas('flight_inquiry_entries', [
            'flight_inquiry_id' => $inquiry->getKey(),
            'entry_type' => FlightInquiryEntryType::Assigned->value,
        ]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'flight_inquiry.assignment_changed']);
    }

    public function test_a_customer_account_cannot_be_made_a_consultant(): void
    {
        $staff = $this->operationsUser();
        $customer = $this->customer();
        $inquiry = $this->createInquiry();

        $this->actingAs($staff)
            ->from(route('admin.flight-inquiries.show', $inquiry))
            ->patch(route('admin.flight-inquiries.assignment', $inquiry), [
                'assigned_to_user_id' => $customer->getKey(),
            ])
            ->assertSessionHasErrors('assigned_to_user_id');

        $this->assertNull($inquiry->refresh()->assigned_to_user_id);
    }

    public function test_the_guarded_workflow_runs_from_new_to_closed(): void
    {
        $staff = $this->operationsUser();
        $customer = $this->customer();
        $inquiry = $this->createInquiry($customer);

        $this->actingAs($staff)
            ->patch(route('admin.flight-inquiries.transition', $inquiry), [
                'status' => FlightInquiryStatus::Contacted->value,
                'notify_traveller' => '1',
            ])
            ->assertRedirect();

        $inquiry->refresh();
        $this->assertSame(FlightInquiryStatus::Contacted, $inquiry->status);
        $this->assertNotNull($inquiry->first_contacted_at);

        $this->actingAs($staff)
            ->patch(route('admin.flight-inquiries.transition', $inquiry), [
                'status' => FlightInquiryStatus::Booked->value,
            ])
            ->assertRedirect();

        $inquiry->refresh();
        $this->assertNotNull($inquiry->booked_at);

        $this->actingAs($staff)
            ->patch(route('admin.flight-inquiries.transition', $inquiry), [
                'status' => FlightInquiryStatus::Closed->value,
                'reason' => 'Tickets issued and sent to the traveller.',
            ])
            ->assertRedirect();

        $inquiry->refresh();
        $this->assertSame(FlightInquiryStatus::Closed, $inquiry->status);
        $this->assertSame('Tickets issued and sent to the traveller.', $inquiry->resolution_reason);
        Notification::assertSentTo($customer, FlightInquiryStatusNotification::class);
    }

    public function test_an_invalid_status_jump_is_rejected(): void
    {
        $staff = $this->operationsUser();
        $inquiry = $this->createInquiry();

        // New may not skip contact and jump straight to booked.
        $this->actingAs($staff)
            ->from(route('admin.flight-inquiries.show', $inquiry))
            ->patch(route('admin.flight-inquiries.transition', $inquiry), [
                'status' => FlightInquiryStatus::Booked->value,
            ])
            ->assertSessionHasErrors('status');

        $this->assertSame(FlightInquiryStatus::New, $inquiry->refresh()->status);
    }

    public function test_closing_and_cancelling_require_a_reason(): void
    {
        $staff = $this->operationsUser();
        $inquiry = $this->createInquiry();

        $this->actingAs($staff)
            ->from(route('admin.flight-inquiries.show', $inquiry))
            ->patch(route('admin.flight-inquiries.transition', $inquiry), [
                'status' => FlightInquiryStatus::Cancelled->value,
            ])
            ->assertSessionHasErrors('reason');

        $this->assertSame(FlightInquiryStatus::New, $inquiry->refresh()->status);
    }

    public function test_only_a_manager_can_reopen_a_resolved_inquiry(): void
    {
        $staff = $this->operationsUser();
        $manager = $this->manager();
        $inquiry = FlightInquiry::factory()->withStatus(FlightInquiryStatus::Cancelled)->create([
            'cancelled_at' => now()->subDay(),
            'resolution_reason' => 'Traveller stopped responding.',
        ]);

        $this->actingAs($staff)
            ->from(route('admin.flight-inquiries.show', $inquiry))
            ->patch(route('admin.flight-inquiries.transition', $inquiry), [
                'status' => FlightInquiryStatus::New->value,
            ])
            ->assertForbidden();

        $this->assertSame(FlightInquiryStatus::Cancelled, $inquiry->refresh()->status);

        app(TransitionFlightInquiry::class)->execute($manager, $inquiry, FlightInquiryStatus::New);

        $inquiry->refresh();
        $this->assertSame(FlightInquiryStatus::New, $inquiry->status);
        $this->assertSame(1, $inquiry->reopen_count);
        $this->assertNull($inquiry->resolution_reason);
        $this->assertNull($inquiry->cancelled_at);
        $this->assertNotNull($inquiry->reopened_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'flight_inquiry.reopened']);
    }

    public function test_reopening_is_bounded_by_the_configured_maximum(): void
    {
        config(['flight_inquiries.reopen.maximum_per_inquiry' => 1]);

        $manager = $this->manager();
        $inquiry = FlightInquiry::factory()->withStatus(FlightInquiryStatus::Closed)->create([
            'reopen_count' => 1,
        ]);

        $this->expectException(ValidationException::class);

        app(TransitionFlightInquiry::class)->execute($manager, $inquiry, FlightInquiryStatus::New);
    }

    public function test_a_resolved_inquiry_cannot_be_reassigned_until_it_is_reopened(): void
    {
        $staff = $this->operationsUser();
        $consultant = $this->operationsUser();
        $inquiry = FlightInquiry::factory()->withStatus(FlightInquiryStatus::Closed)->create();

        $this->actingAs($staff)
            ->from(route('admin.flight-inquiries.show', $inquiry))
            ->patch(route('admin.flight-inquiries.assignment', $inquiry), [
                'assigned_to_user_id' => $consultant->getKey(),
            ])
            ->assertSessionHasErrors('assigned_to_user_id');

        $this->assertNull($inquiry->refresh()->assigned_to_user_id);
    }

    public function test_staff_record_internal_notes_and_communications(): void
    {
        $staff = $this->operationsUser();
        $inquiry = $this->createInquiry();

        $this->actingAs($staff)
            ->post(route('admin.flight-inquiries.entries.store', $inquiry), [
                'entry_type' => FlightInquiryEntryType::InternalNote->value,
                'body' => 'Fare basis checked against the corporate agreement.',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->actingAs($staff)
            ->post(route('admin.flight-inquiries.entries.store', $inquiry), [
                'entry_type' => FlightInquiryEntryType::PhoneCall->value,
                'body' => 'Called the traveller and confirmed flexible dates.',
            ])
            ->assertRedirect();

        // System entry types cannot be forged through the manual form.
        $this->actingAs($staff)
            ->from(route('admin.flight-inquiries.show', $inquiry))
            ->post(route('admin.flight-inquiries.entries.store', $inquiry), [
                'entry_type' => FlightInquiryEntryType::StatusChanged->value,
                'body' => 'Pretending the enquiry moved on its own.',
            ])
            ->assertSessionHasErrors('entry_type');

        $this->assertSame(2, $inquiry->entries()->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'flight_inquiry.note_recorded']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'flight_inquiry.communication_recorded']);
    }

    public function test_a_deactivated_consultant_cannot_be_assigned_under_the_lock(): void
    {
        $staff = $this->operationsUser();
        $consultant = $this->operationsUser();
        $inquiry = $this->createInquiry();

        $consultant->forceFill(['status' => AccountStatus::Suspended])->save();

        $this->expectException(AuthorizationException::class);

        app(AssignFlightInquiry::class)
            ->execute($staff, $inquiry, $consultant);
    }

    public function test_a_transition_can_be_applied_without_emailing_the_traveller(): void
    {
        $staff = $this->operationsUser();
        $customer = $this->customer();
        $inquiry = $this->createInquiry($customer);

        Notification::fake();

        $this->actingAs($staff)
            ->patch(route('admin.flight-inquiries.transition', $inquiry), [
                'status' => FlightInquiryStatus::Contacted->value,
            ])
            ->assertRedirect();

        $this->assertSame(FlightInquiryStatus::Contacted, $inquiry->refresh()->status);
        Notification::assertNothingSentTo($customer);
    }
}
