<?php

namespace Tests\Feature;

use App\Actions\CarHire\CreateCarHireBooking;
use App\Support\LegalDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The terms PISFA trades on.
 *
 * The risk these guard is not a broken page. It is a policy that says one thing
 * on the website and another on the contract, or that quotes a cancellation
 * window the software does not enforce — a promise the system itself will break.
 */
class MarketingLegalPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_the_booking_terms_page_renders_every_service(): void
    {
        $response = $this->get(route('booking-terms'))->assertOk();

        foreach (LegalDocuments::bookingTerms() as $section) {
            $response->assertSee($section['title']);
        }
    }

    public function test_the_terms_name_the_company_a_customer_is_contracting_with(): void
    {
        // A limited company's terms have to say which company. "PISFA" alone
        // does not identify anybody a customer could take to court.
        $this->get(route('booking-terms'))
            ->assertOk()
            ->assertSee(config('pisfa.company.legal_name'))
            ->assertSee((string) config('pisfa.company.registration_number'));
    }

    public function test_the_cancellation_policy_renders_a_table_for_each_service(): void
    {
        $response = $this->get(route('cancellation-policy'))->assertOk();

        foreach (LegalDocuments::cancellationPolicy()['tiers'] as $tier) {
            $response->assertSee($tier['service']);
        }
    }

    /**
     * The figures on the page are the figures the code enforces.
     *
     * A cancellation policy stating forty-eight hours while the booking code
     * allows twenty-four is worse than none: it is a promise that will be
     * broken by the software the moment somebody relies on it.
     */
    public function test_the_published_windows_are_the_ones_the_software_enforces(): void
    {
        $this->get(route('cancellation-policy'))
            ->assertOk()
            ->assertSee((string) config('pisfa.car_hire.cancellation_cutoff_hours'))
            ->assertSee((string) config('pisfa.airport_transfers.cancellation_cutoff_hours'));

        $this->get(route('booking-terms'))
            ->assertOk()
            ->assertSee((string) config('pisfa.car_hire.maximum_days'))
            ->assertSee((string) config('pisfa.tours.cancellation_cutoff_hours'));
    }

    public function test_the_rental_contract_carries_the_same_terms_as_the_website(): void
    {
        // One source. The clause a customer signs at handover is the clause the
        // car hire terms page showed them before they booked.
        $clauses = LegalDocuments::rentalAgreementClauses();

        $this->assertNotEmpty($clauses);
        $this->assertSame(LegalDocuments::bookingTerms()['car-hire']['clauses'], $clauses);

        $response = $this->get(route('booking-terms'))->assertOk();

        foreach ($clauses as $clause) {
            $response->assertSee($clause);
        }
    }

    public function test_the_contract_no_longer_defers_to_a_policy_that_did_not_exist(): void
    {
        // It used to say insurance scope, damage responsibility and
        // cancellation charges "require the approved PISFA policy supplied
        // before handover" — deferring to a document nobody had written.
        $source = file_get_contents(
            (new \ReflectionClass(CreateCarHireBooking::class))->getFileName() ?: '',
        );

        $this->assertIsString($source);
        $this->assertStringNotContainsString('does not invent or override that policy', $source);
        $this->assertStringContainsString('LegalDocuments::rentalAgreementClauses()', $source);
    }

    public function test_the_privacy_policy_names_the_registered_company(): void
    {
        $this->get(route('privacy'))
            ->assertOk()
            ->assertSee(config('pisfa.company.legal_name'))
            ->assertSee('Data Protection and Privacy Act 2019');
    }

    public function test_every_legal_page_is_reachable_from_the_footer(): void
    {
        // A policy nobody can find is a policy that does not exist.
        $this->get(route('home'))
            ->assertOk()
            ->assertSee(route('booking-terms'), false)
            ->assertSee(route('cancellation-policy'), false)
            ->assertSee(route('privacy'), false);
    }

    public function test_the_permit_rule_is_stated_where_a_customer_will_look(): void
    {
        // The single most expensive surprise in a Ugandan safari booking.
        $this->get(route('cancellation-policy'))
            ->assertOk()
            ->assertSee('never refundable once purchased');
    }
}
