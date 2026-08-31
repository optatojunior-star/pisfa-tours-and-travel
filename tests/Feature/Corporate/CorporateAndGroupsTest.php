<?php

namespace Tests\Feature\Corporate;

use App\Actions\Corporate\ManageCorporateMembers;
use App\Actions\Corporate\ManageGroupManifest;
use App\Actions\Corporate\SaveCorporateAccount;
use App\Actions\Corporate\SaveGroupBooking;
use App\Actions\Corporate\TransitionCorporateAccount;
use App\Actions\Corporate\TransitionGroupBooking;
use App\Enums\AccountStatus;
use App\Enums\CorporateAccountStatus;
use App\Enums\CorporateMemberRole;
use App\Enums\GroupBookingStatus;
use App\Enums\InvoiceStatus;
use App\Enums\UserRole;
use App\Http\Middleware\EnsureTwoFactorAuthenticationIsConfigured;
use App\Models\AuditLog;
use App\Models\CorporateAccount;
use App\Models\CorporateMember;
use App\Models\GroupBooking;
use App\Models\GroupTraveler;
use App\Models\Invoice;
use App\Models\User;
use App\Notifications\Corporate\GroupBookingUpdatedNotification;
use App\Services\Corporate\CorporateCreditQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CorporateAndGroupsTest extends TestCase
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
        ], $attributes));
    }

    private function staff(): User
    {
        return $this->user(UserRole::Staff, ['two_factor_required' => false]);
    }

    private function manager(): User
    {
        return $this->user(UserRole::Manager, [
            'two_factor_secret' => 'configured-for-feature-test',
            'two_factor_confirmed_at' => now(),
        ]);
    }

    /** @return array<string, int> */
    private function verifiedSession(User $manager): array
    {
        return [
            EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_AT_SESSION_KEY => now()->timestamp,
            EnsureTwoFactorAuthenticationIsConfigured::VERIFIED_USER_SESSION_KEY => $manager->id,
        ];
    }

    private function customer(): User
    {
        return $this->user(UserRole::Customer);
    }

    /** @return array<string, mixed> */
    private function accountPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Nile Foundation Uganda',
            'billing_contact_name' => 'Grace Atim',
            'billing_contact_email' => 'accounts@nilefoundation.example',
            'billing_contact_phone' => '+256414700700',
            'payment_terms_days' => 30,
            'credit_limit' => '20000000',
            'currency' => 'UGX',
            'discount_bps' => 0,
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function groupPayload(array $overrides = []): array
    {
        return array_merge([
            'title' => 'Team retreat to Jinja',
            'service_kind' => 'corporate-travel',
            'starts_on' => '2026-09-15',
            'ends_on' => '2026-09-17',
            'headcount' => 12,
            'pickup_location' => 'Kampala office',
            'destination' => 'Jinja',
            'currency' => 'UGX',
        ], $overrides);
    }

    /** An issued invoice against the account, so credit is genuinely consumed. */
    private function issuedInvoice(CorporateAccount $account, int $totalMinor, ?string $dueOn = null): Invoice
    {
        return Invoice::factory()->create([
            'corporate_account_id' => $account->getKey(),
            'status' => InvoiceStatus::Issued,
            'currency' => $account->currency,
            'subtotal_minor' => $totalMinor,
            'total_minor' => $totalMinor,
            'issued_on' => '2026-08-01',
            'due_on' => $dueOn ?? '2026-08-31',
        ]);
    }

    // ---- Credit, computed from live invoices ------------------------------

    public function test_an_account_with_no_invoices_has_its_whole_limit_available(): void
    {
        $account = CorporateAccount::factory()->active()->withCreditLimit(20_000_000)->create();

        $position = app(CorporateCreditQuery::class)->position($account);

        $this->assertSame(0, $position['outstanding_minor']);
        $this->assertSame(20_000_000, $position['available_minor']);
    }

    public function test_an_issued_invoice_consumes_credit(): void
    {
        $account = CorporateAccount::factory()->active()->withCreditLimit(20_000_000)->create();
        $this->issuedInvoice($account, 8_000_000);

        $position = app(CorporateCreditQuery::class)->position($account);

        $this->assertSame(8_000_000, $position['outstanding_minor']);
        $this->assertSame(12_000_000, $position['available_minor']);
    }

    public function test_a_draft_invoice_consumes_nothing(): void
    {
        $account = CorporateAccount::factory()->active()->withCreditLimit(20_000_000)->create();

        Invoice::factory()->create([
            'corporate_account_id' => $account->getKey(),
            'status' => InvoiceStatus::Draft,
            'currency' => 'UGX',
            'total_minor' => 8_000_000,
        ]);

        $this->assertSame(0, app(CorporateCreditQuery::class)->position($account)['outstanding_minor']);
    }

    public function test_a_cancelled_invoice_releases_the_credit_it_held(): void
    {
        $account = CorporateAccount::factory()->active()->withCreditLimit(20_000_000)->create();
        $invoice = $this->issuedInvoice($account, 8_000_000);

        $this->assertSame(8_000_000, app(CorporateCreditQuery::class)->position($account)['outstanding_minor']);

        // No stored balance to update: cancelling the invoice is enough.
        $invoice->forceFill(['status' => InvoiceStatus::Cancelled])->save();

        $this->assertSame(0, app(CorporateCreditQuery::class)->position($account)['outstanding_minor']);
    }

    public function test_invoices_in_another_currency_are_counted_separately_not_added(): void
    {
        $account = CorporateAccount::factory()->active()->withCreditLimit(20_000_000, 'UGX')->create();
        $this->issuedInvoice($account, 5_000_000);

        Invoice::factory()->create([
            'corporate_account_id' => $account->getKey(),
            'status' => InvoiceStatus::Issued,
            'currency' => 'USD',
            'total_minor' => 400_000,
        ]);

        $position = app(CorporateCreditQuery::class)->position($account);

        $this->assertSame(5_000_000, $position['outstanding_minor']);
        $this->assertSame(1, $position['other_currency_count']);
    }

    public function test_an_overdue_invoice_is_reported_as_overdue(): void
    {
        $account = CorporateAccount::factory()->active()->withCreditLimit(20_000_000)->create();
        $this->issuedInvoice($account, 3_000_000, dueOn: '2026-08-01');

        $this->assertSame(3_000_000, app(CorporateCreditQuery::class)->position($account)['overdue_minor']);
    }

    public function test_credit_in_another_currency_is_never_carried(): void
    {
        $account = CorporateAccount::factory()->active()->withCreditLimit(20_000_000, 'UGX')->create();

        $this->assertFalse(app(CorporateCreditQuery::class)->canCarry($account, 100, 'USD'));
    }

    // ---- Terms -------------------------------------------------------------

    public function test_a_manager_creates_an_account_as_a_prospect(): void
    {
        $account = app(SaveCorporateAccount::class)->create($this->manager(), $this->accountPayload());

        $this->assertSame(CorporateAccountStatus::Prospect, $account->status);
        $this->assertSame(20_000_000, $account->credit_limit_minor);
        $this->assertDatabaseHas('audit_logs', ['event' => 'corporate_account.created']);
    }

    public function test_staff_cannot_set_terms(): void
    {
        $this->expectException(AuthorizationException::class);

        app(SaveCorporateAccount::class)->create($this->staff(), $this->accountPayload());
    }

    public function test_a_credit_limit_cannot_be_cut_below_what_is_owed(): void
    {
        $manager = $this->manager();
        $account = CorporateAccount::factory()->active()->withCreditLimit(20_000_000)->create();
        $this->issuedInvoice($account, 8_000_000);

        $this->expectException(ValidationException::class);

        app(SaveCorporateAccount::class)->updateTerms($manager, $account, [
            'payment_terms_days' => 30,
            'credit_limit' => '5000000',
            'currency' => 'UGX',
            'discount_bps' => 0,
        ]);
    }

    public function test_the_billing_currency_cannot_change_while_money_is_owed(): void
    {
        $manager = $this->manager();
        $account = CorporateAccount::factory()->active()->withCreditLimit(20_000_000)->create();
        $this->issuedInvoice($account, 1_000_000);

        $this->expectException(ValidationException::class);

        app(SaveCorporateAccount::class)->updateTerms($manager, $account, [
            'payment_terms_days' => 30,
            'credit_limit' => '20000',
            'currency' => 'USD',
            'discount_bps' => 0,
        ]);
    }

    public function test_a_discount_at_or_above_one_hundred_percent_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        app(SaveCorporateAccount::class)->create(
            $this->manager(),
            $this->accountPayload(['discount_bps' => 10000]),
        );
    }

    public function test_a_traded_account_keeps_its_slug_when_renamed(): void
    {
        $manager = $this->manager();
        $account = CorporateAccount::factory()->active()->create();
        $original = $account->slug;

        $updated = app(SaveCorporateAccount::class)->updateDetails(
            $manager,
            $account,
            $this->accountPayload(['name' => 'A Completely Different Name']),
        );

        $this->assertSame($original, $updated->slug);
        $this->assertSame('A Completely Different Name', $updated->name);
    }

    public function test_a_closed_account_is_read_only(): void
    {
        $account = CorporateAccount::factory()->closed()->create();

        $this->expectException(ValidationException::class);

        app(SaveCorporateAccount::class)->updateDetails($this->manager(), $account, $this->accountPayload());
    }

    // ---- The account's life -------------------------------------------------

    public function test_every_account_status_is_reachable(): void
    {
        $manager = $this->manager();
        $action = app(TransitionCorporateAccount::class);

        $account = CorporateAccount::factory()->create();
        $this->assertSame(CorporateAccountStatus::Prospect, $account->status);

        $active = $action->activate($manager, $account);
        $this->assertSame(CorporateAccountStatus::Active, $active->status);
        $this->assertNotNull($active->activated_at);

        $suspended = $action->suspend($manager, $active, 'Two invoices past due.');
        $this->assertSame(CorporateAccountStatus::Suspended, $suspended->status);

        $restored = $action->activate($manager, $suspended);
        $this->assertSame(CorporateAccountStatus::Active, $restored->status);

        $closed = $action->close($manager, $restored, 'The contract was not renewed.');
        $this->assertSame(CorporateAccountStatus::Closed, $closed->status);

        $reopened = $action->reopen($manager, $closed);
        $this->assertSame(CorporateAccountStatus::Prospect, $reopened->status);
    }

    public function test_restoring_an_account_keeps_the_day_it_first_traded(): void
    {
        $manager = $this->manager();
        $action = app(TransitionCorporateAccount::class);

        $account = $action->activate($manager, CorporateAccount::factory()->create());
        $first = $account->activated_at;

        $this->travel(5)->days();

        $action->suspend($manager, $account, 'Paperwork pending.');
        $again = $action->activate($manager, $account->fresh());

        $this->assertTrue($first->equalTo($again->activated_at));
    }

    public function test_an_account_that_owes_money_cannot_be_closed(): void
    {
        $manager = $this->manager();
        $account = CorporateAccount::factory()->active()->create();
        $this->issuedInvoice($account, 4_000_000);

        $this->expectException(ValidationException::class);

        app(TransitionCorporateAccount::class)->close($manager, $account, 'Contract ended.');
    }

    public function test_an_account_with_a_confirmed_trip_cannot_be_closed(): void
    {
        $manager = $this->manager();
        $account = CorporateAccount::factory()->active()->create();

        GroupBooking::factory()->forAccount($account)
            ->organisedBy($this->customer())
            ->status(GroupBookingStatus::Confirmed)->create();

        $this->expectException(ValidationException::class);

        app(TransitionCorporateAccount::class)->close($manager, $account, 'Contract ended.');
    }

    // ---- Membership ---------------------------------------------------------

    public function test_a_member_is_added_with_a_role(): void
    {
        $manager = $this->manager();
        $account = CorporateAccount::factory()->active()->create();
        $person = $this->customer();

        $member = app(ManageCorporateMembers::class)
            ->add($manager, $account, $person, CorporateMemberRole::Booker, 'Travel officer');

        $this->assertSame(CorporateMemberRole::Booker, $member->role);
        $this->assertTrue($member->is_active);
    }

    public function test_only_a_customer_can_be_put_on_a_company_account(): void
    {
        $this->expectException(ValidationException::class);

        app(ManageCorporateMembers::class)->add(
            $this->manager(),
            CorporateAccount::factory()->active()->create(),
            $this->staff(),
            CorporateMemberRole::Booker,
        );
    }

    public function test_the_database_refuses_two_memberships_for_one_person(): void
    {
        $account = CorporateAccount::factory()->create();
        $person = $this->customer();

        CorporateMember::factory()->on($account)->forUser($person)->create();

        $this->expectException(QueryException::class);

        CorporateMember::factory()->on($account)->forUser($person)->create();
    }

    public function test_re_adding_somebody_restores_their_membership_rather_than_failing(): void
    {
        $manager = $this->manager();
        $account = CorporateAccount::factory()->active()->create();
        $person = $this->customer();

        CorporateMember::factory()->on($account)->forUser($person)->deactivated()->create();

        $member = app(ManageCorporateMembers::class)
            ->add($manager, $account, $person, CorporateMemberRole::Approver);

        $this->assertTrue($member->is_active);
        $this->assertSame(CorporateMemberRole::Approver, $member->role);
        $this->assertSame(1, CorporateMember::query()->count());
    }

    public function test_the_last_administrator_cannot_be_removed(): void
    {
        $manager = $this->manager();
        $account = CorporateAccount::factory()->active()->create();

        $admin = CorporateMember::factory()->on($account)
            ->forUser($this->customer())
            ->role(CorporateMemberRole::Administrator)->create();

        $this->expectException(ValidationException::class);

        app(ManageCorporateMembers::class)->deactivate($manager, $account, $admin);
    }

    public function test_an_administrator_can_be_removed_once_there_is_another(): void
    {
        $manager = $this->manager();
        $account = CorporateAccount::factory()->active()->create();

        $first = CorporateMember::factory()->on($account)->forUser($this->customer())
            ->role(CorporateMemberRole::Administrator)->create();
        CorporateMember::factory()->on($account)->forUser($this->customer())
            ->role(CorporateMemberRole::Administrator)->create();

        $removed = app(ManageCorporateMembers::class)->deactivate($manager, $account, $first);

        $this->assertFalse($removed->is_active);
        // The row survives, so the bookings they raised still make sense.
        $this->assertNotNull($removed->deactivated_at);
    }

    public function test_a_deactivated_member_has_no_authority(): void
    {
        $account = CorporateAccount::factory()->active()->create();
        $person = $this->customer();

        $member = CorporateMember::factory()->on($account)->forUser($person)
            ->role(CorporateMemberRole::Administrator)->deactivated()->create();

        $this->assertFalse($member->canBook());
        $this->assertFalse($member->canManageMembers());
        $this->assertFalse($person->can('book', $account));
    }

    public function test_an_account_administrator_can_manage_their_own_colleagues(): void
    {
        $account = CorporateAccount::factory()->active()->create();
        $admin = $this->customer();

        CorporateMember::factory()->on($account)->forUser($admin)
            ->role(CorporateMemberRole::Administrator)->create();

        $colleague = $this->customer();

        $member = app(ManageCorporateMembers::class)
            ->add($admin, $account, $colleague, CorporateMemberRole::Traveller);

        $this->assertSame(CorporateMemberRole::Traveller, $member->role);
    }

    public function test_an_ordinary_member_cannot_manage_colleagues(): void
    {
        $account = CorporateAccount::factory()->active()->create();
        $booker = $this->customer();

        CorporateMember::factory()->on($account)->forUser($booker)
            ->role(CorporateMemberRole::Booker)->create();

        $this->expectException(AuthorizationException::class);

        app(ManageCorporateMembers::class)
            ->add($booker, $account, $this->customer(), CorporateMemberRole::Traveller);
    }

    // ---- Group bookings ------------------------------------------------------

    public function test_anybody_signed_in_can_raise_a_group_without_a_company(): void
    {
        $organiser = $this->customer();

        $booking = app(SaveGroupBooking::class)->create($organiser, $this->groupPayload());

        $this->assertNull($booking->corporate_account_id);
        $this->assertSame(GroupBookingStatus::Enquiry, $booking->status);
        $this->assertSame($organiser->getKey(), $booking->organiser_id);
    }

    public function test_a_group_of_one_is_not_a_group(): void
    {
        $this->expectException(ValidationException::class);

        app(SaveGroupBooking::class)->create($this->customer(), $this->groupPayload(['headcount' => 1]));
    }

    public function test_a_trip_cannot_start_in_the_past(): void
    {
        $this->expectException(ValidationException::class);

        app(SaveGroupBooking::class)->create($this->customer(), $this->groupPayload([
            'starts_on' => '2026-08-01',
            'ends_on' => '2026-08-03',
        ]));
    }

    public function test_a_booker_can_raise_a_group_on_the_company(): void
    {
        $account = CorporateAccount::factory()->active()->create();
        $booker = $this->customer();

        CorporateMember::factory()->on($account)->forUser($booker)
            ->role(CorporateMemberRole::Booker)->create();

        $booking = app(SaveGroupBooking::class)->create($booker, $this->groupPayload(), $account);

        $this->assertSame($account->getKey(), $booking->corporate_account_id);
    }

    public function test_a_traveller_cannot_raise_a_group_on_the_company(): void
    {
        $account = CorporateAccount::factory()->active()->create();
        $person = $this->customer();

        CorporateMember::factory()->on($account)->forUser($person)
            ->role(CorporateMemberRole::Traveller)->create();

        $this->expectException(AuthorizationException::class);

        app(SaveGroupBooking::class)->create($person, $this->groupPayload(), $account);
    }

    public function test_a_suspended_account_cannot_take_a_new_group(): void
    {
        $account = CorporateAccount::factory()->suspended()->create();
        $booker = $this->customer();

        CorporateMember::factory()->on($account)->forUser($booker)
            ->role(CorporateMemberRole::Booker)->create();

        $this->expectException(AuthorizationException::class);

        app(SaveGroupBooking::class)->create($booker, $this->groupPayload(), $account);
    }

    public function test_the_headcount_cannot_drop_below_the_names_already_listed(): void
    {
        $organiser = $this->customer();
        $booking = GroupBooking::factory()->organisedBy($organiser)->headcount(10)->create();

        GroupTraveler::factory()->count(6)->on($booking)->create();

        $this->expectException(ValidationException::class);

        app(SaveGroupBooking::class)->update($organiser, $booking, $this->groupPayload(['headcount' => 4]));
    }

    // ---- The manifest -----------------------------------------------------------

    public function test_the_list_cannot_exceed_the_headcount(): void
    {
        $organiser = $this->customer();
        $booking = GroupBooking::factory()->organisedBy($organiser)->headcount(2)->create();
        $action = app(ManageGroupManifest::class);

        $action->add($organiser, $booking, ['full_name' => 'Grace Atim', 'traveler_type' => 'adult']);
        $action->add($organiser, $booking, ['full_name' => 'Peter Okot', 'traveler_type' => 'adult']);

        $this->expectException(ValidationException::class);

        $action->add($organiser, $booking, ['full_name' => 'One Too Many', 'traveler_type' => 'adult']);
    }

    public function test_a_traveller_can_be_removed_and_the_seat_reused(): void
    {
        $organiser = $this->customer();
        $booking = GroupBooking::factory()->organisedBy($organiser)->headcount(1)->create();
        $action = app(ManageGroupManifest::class);

        $traveler = $action->add($organiser, $booking, [
            'full_name' => 'Grace Atim',
            'traveler_type' => 'adult',
        ]);

        $action->remove($organiser, $booking->fresh(), $traveler);

        $replacement = $action->add($organiser, $booking->fresh(), [
            'full_name' => 'Peter Okot',
            'traveler_type' => 'adult',
        ]);

        $this->assertSame('Peter Okot', $replacement->full_name);
        $this->assertSame(1, $booking->fresh()->manifestCount());
    }

    public function test_a_traveller_from_another_group_cannot_be_touched(): void
    {
        $organiser = $this->customer();
        $mine = GroupBooking::factory()->organisedBy($organiser)->create();
        $theirs = GroupBooking::factory()->organisedBy($this->customer())->create();
        $stranger = GroupTraveler::factory()->on($theirs)->create();

        $this->expectException(ValidationException::class);

        app(ManageGroupManifest::class)->remove($organiser, $mine, $stranger);
    }

    public function test_the_list_is_closed_once_the_group_is_under_way(): void
    {
        $organiser = $this->customer();
        $booking = GroupBooking::factory()->organisedBy($organiser)
            ->status(GroupBookingStatus::InProgress)->create();

        $this->expectException(ValidationException::class);

        app(ManageGroupManifest::class)->add($organiser, $booking, [
            'full_name' => 'Late Arrival',
            'traveler_type' => 'adult',
        ]);
    }

    public function test_identity_documents_are_never_serialised(): void
    {
        $traveler = GroupTraveler::factory()->create([
            'identity_document' => 'CM90210987654321',
            'date_of_birth' => '1990-05-01',
        ]);

        $this->assertArrayNotHasKey('identity_document', $traveler->toArray());
        $this->assertArrayNotHasKey('date_of_birth', $traveler->toArray());
    }

    public function test_the_audit_trail_records_the_name_but_not_the_document(): void
    {
        $organiser = $this->customer();
        $booking = GroupBooking::factory()->organisedBy($organiser)->headcount(4)->create();

        app(ManageGroupManifest::class)->add($organiser, $booking, [
            'full_name' => 'Grace Atim',
            'traveler_type' => 'adult',
            'identity_document' => 'CM90210987654321',
        ]);

        $entry = AuditLog::query()->where('event', 'group_traveler.added')->firstOrFail();
        $encoded = (string) json_encode($entry->new_values);

        $this->assertStringContainsString('Grace Atim', $encoded);
        $this->assertStringNotContainsString('CM90210987654321', $encoded);
    }

    // ---- Confirming ---------------------------------------------------------------

    public function test_a_group_cannot_be_confirmed_with_an_incomplete_list(): void
    {
        $manager = $this->manager();
        $booking = GroupBooking::factory()->organisedBy($this->customer())
            ->headcount(10)
            ->status(GroupBookingStatus::ManifestPending)->create();

        GroupTraveler::factory()->count(7)->on($booking)->create();

        $this->expectException(ValidationException::class);

        app(TransitionGroupBooking::class)->confirm($manager, $booking);
    }

    public function test_a_group_with_a_complete_list_confirms(): void
    {
        $manager = $this->manager();
        $booking = GroupBooking::factory()->organisedBy($this->customer())
            ->headcount(3)
            ->status(GroupBookingStatus::ManifestPending)->create();

        GroupTraveler::factory()->count(3)->on($booking)->create();

        $confirmed = app(TransitionGroupBooking::class)->confirm($manager, $booking);

        $this->assertSame(GroupBookingStatus::Confirmed, $confirmed->status);
        $this->assertNotNull($confirmed->confirmed_at);
    }

    public function test_a_group_beyond_the_credit_limit_is_refused(): void
    {
        $manager = $this->manager();
        $account = CorporateAccount::factory()->active()->withCreditLimit(10_000_000)->create();
        $this->issuedInvoice($account, 9_000_000);

        $booking = GroupBooking::factory()->forAccount($account)
            ->organisedBy($this->customer())
            ->headcount(2)
            ->priced(5_000_000)
            ->status(GroupBookingStatus::ManifestPending)->create();

        GroupTraveler::factory()->count(2)->on($booking)->create();

        $this->expectException(ValidationException::class);

        app(TransitionGroupBooking::class)->confirm($manager, $booking);
    }

    public function test_a_group_inside_the_credit_limit_confirms(): void
    {
        $manager = $this->manager();
        $account = CorporateAccount::factory()->active()->withCreditLimit(10_000_000)->create();
        $this->issuedInvoice($account, 2_000_000);

        $booking = GroupBooking::factory()->forAccount($account)
            ->organisedBy($this->customer())
            ->headcount(2)
            ->priced(5_000_000)
            ->status(GroupBookingStatus::ManifestPending)->create();

        GroupTraveler::factory()->count(2)->on($booking)->create();

        $confirmed = app(TransitionGroupBooking::class)->confirm($manager, $booking);

        $this->assertSame(GroupBookingStatus::Confirmed, $confirmed->status);
    }

    public function test_an_account_with_no_credit_limit_is_not_credit_checked(): void
    {
        $manager = $this->manager();
        $account = CorporateAccount::factory()->active()->withCreditLimit(0)->create();

        $booking = GroupBooking::factory()->forAccount($account)
            ->organisedBy($this->customer())
            ->headcount(2)
            ->priced(50_000_000)
            ->status(GroupBookingStatus::ManifestPending)->create();

        GroupTraveler::factory()->count(2)->on($booking)->create();

        // No credit means pay up front, not "refuse the booking".
        $confirmed = app(TransitionGroupBooking::class)->confirm($manager, $booking);

        $this->assertSame(GroupBookingStatus::Confirmed, $confirmed->status);
    }

    public function test_every_group_status_is_reachable(): void
    {
        $manager = $this->manager();
        $action = app(TransitionGroupBooking::class);

        $booking = GroupBooking::factory()->organisedBy($this->customer())
            ->headcount(2)->priced(3_000_000)->create();
        GroupTraveler::factory()->count(2)->on($booking)->create();

        $this->assertSame(GroupBookingStatus::Enquiry, $booking->status);

        $quoted = $action->quote($manager, $booking);
        $this->assertSame(GroupBookingStatus::Quoted, $quoted->status);

        $pending = $action->requestManifest($manager, $quoted);
        $this->assertSame(GroupBookingStatus::ManifestPending, $pending->status);

        $confirmed = $action->confirm($manager, $pending);
        $this->assertSame(GroupBookingStatus::Confirmed, $confirmed->status);

        $started = $action->start($manager, $confirmed);
        $this->assertSame(GroupBookingStatus::InProgress, $started->status);

        $completed = $action->complete($manager, $started);
        $this->assertSame(GroupBookingStatus::Completed, $completed->status);

        $cancelled = $action->cancel(
            $manager,
            GroupBooking::factory()->organisedBy($this->customer())->create(),
            'The client called it off.',
        );
        $this->assertSame(GroupBookingStatus::Cancelled, $cancelled->status);
    }

    public function test_a_group_cannot_be_quoted_without_a_price(): void
    {
        $manager = $this->manager();
        $booking = GroupBooking::factory()->organisedBy($this->customer())->create();

        $this->expectException(ValidationException::class);

        app(TransitionGroupBooking::class)->quote($manager, $booking);
    }

    public function test_a_completed_group_is_final(): void
    {
        $manager = $this->manager();
        $booking = GroupBooking::factory()->organisedBy($this->customer())
            ->status(GroupBookingStatus::Completed)->create();

        $this->assertSame([], $booking->status->allowedTransitions());

        $this->expectException(ValidationException::class);

        app(TransitionGroupBooking::class)->cancel($manager, $booking, 'Changed our mind.');
    }

    public function test_confirming_notifies_the_organiser(): void
    {
        $manager = $this->manager();
        $organiser = $this->customer();
        $booking = GroupBooking::factory()->organisedBy($organiser)
            ->headcount(1 + 1)
            ->status(GroupBookingStatus::ManifestPending)->create();

        GroupTraveler::factory()->count(2)->on($booking)->create();

        app(TransitionGroupBooking::class)->confirm($manager, $booking);

        Notification::assertSentTo($organiser, GroupBookingUpdatedNotification::class);
    }

    // ---- Surfaces -------------------------------------------------------------------

    public function test_an_organiser_sees_their_own_group_and_not_another(): void
    {
        $organiser = $this->customer();
        $mine = GroupBooking::factory()->organisedBy($organiser)->create();
        $theirs = GroupBooking::factory()->organisedBy($this->customer())->create();

        $this->actingAs($organiser)->get(route('portal.groups.show', $mine))->assertOk();
        $this->actingAs($organiser)->get(route('portal.groups.show', $theirs))->assertForbidden();
    }

    public function test_a_colleague_on_the_account_can_read_the_group(): void
    {
        $account = CorporateAccount::factory()->active()->create();
        $organiser = $this->customer();
        $colleague = $this->customer();

        CorporateMember::factory()->on($account)->forUser($organiser)
            ->role(CorporateMemberRole::Booker)->create();
        CorporateMember::factory()->on($account)->forUser($colleague)
            ->role(CorporateMemberRole::Traveller)->create();

        $booking = GroupBooking::factory()->forAccount($account)->organisedBy($organiser)->create();

        $this->actingAs($colleague)->get(route('portal.groups.show', $booking))->assertOk();
    }

    public function test_a_customer_can_raise_a_group_through_the_portal(): void
    {
        $this->actingAs($this->customer())
            ->post(route('portal.groups.store'), $this->groupPayload())
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(1, GroupBooking::query()->count());
    }

    public function test_the_corporate_console_is_closed_to_customers(): void
    {
        $this->actingAs($this->customer())
            ->get(route('admin.corporate.index'))
            ->assertForbidden();
    }

    public function test_staff_can_work_the_corporate_console(): void
    {
        $staff = $this->staff();
        $account = CorporateAccount::factory()->active()->create();
        GroupBooking::factory()->organisedBy($this->customer())->create();

        $this->actingAs($staff)->get(route('admin.corporate.index'))->assertOk()->assertSee($account->name);
        $this->actingAs($staff)->get(route('admin.corporate.show', $account))->assertOk();
        $this->actingAs($staff)->get(route('admin.corporate.groups.index'))->assertOk();
    }

    public function test_a_manager_confirms_a_group_through_the_console(): void
    {
        $manager = $this->manager();
        $booking = GroupBooking::factory()->organisedBy($this->customer())
            ->headcount(2)
            ->status(GroupBookingStatus::ManifestPending)->create();

        GroupTraveler::factory()->count(2)->on($booking)->create();

        $this->actingAs($manager)
            ->withSession($this->verifiedSession($manager))
            ->post(route('admin.corporate.groups.confirm', $booking))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertSame(GroupBookingStatus::Confirmed, $booking->fresh()->status);
    }

    public function test_a_group_is_reachable_from_the_unified_booking_list(): void
    {
        $staff = $this->staff();
        $booking = GroupBooking::factory()->organisedBy($this->customer())
            ->status(GroupBookingStatus::Confirmed)->create();

        $this->actingAs($staff)
            ->get(route('admin.bookings.index', ['source' => 'groups']))
            ->assertOk()
            ->assertSee($booking->reference);
    }

    public function test_internal_notes_are_never_serialised(): void
    {
        $account = CorporateAccount::factory()->create(['internal_notes' => 'Slow payer']);
        $booking = GroupBooking::factory()->create(['internal_notes' => 'Chase the deposit']);

        $this->assertArrayNotHasKey('internal_notes', $account->toArray());
        $this->assertArrayNotHasKey('internal_notes', $booking->toArray());
    }
}
