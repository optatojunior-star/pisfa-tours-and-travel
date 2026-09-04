<?php

namespace App\Support;

/**
 * The terms PISFA trades on, in one place.
 *
 * These are read by the public policy pages AND stamped into the rental
 * agreement each customer signs. That is the point of putting them here rather
 * than writing them twice: a cancellation window quoted on the website and a
 * different one printed on a contract is the kind of contradiction a customer
 * finds at exactly the wrong moment.
 *
 * Numbers are never restated here. Every figure below is read from
 * config/pisfa.php and config/car_hire.php, so the cancellation window on the
 * page is the same integer the booking code enforces. A policy that says
 * forty-eight hours while the software allows twenty-four is worse than no
 * policy: it is a promise the system will break.
 *
 * ---------------------------------------------------------------------------
 * BEFORE THIS BINDS ANYONE
 *
 * This is a working draft written to match the services PISFA actually runs and
 * the rules the software actually enforces. It is not legal advice and has not
 * been reviewed by a Ugandan advocate. Cancellation charges, liability limits,
 * insurance excess and data-protection wording are the clauses that decide what
 * happens when something goes wrong, and they are the ones most likely to need
 * changing for Ugandan law and for PISFA's own insurance policy.
 *
 * Have an advocate read it before a customer is asked to accept it.
 * ---------------------------------------------------------------------------
 */
final class LegalDocuments
{
    /** The date these terms last changed, shown to customers. */
    public const LAST_UPDATED = '4 September 2026';

    /**
     * The version stamped onto contracts.
     *
     * A contract records which version it was accepted under, so changing the
     * terms never silently rewrites an agreement somebody already signed.
     */
    public const VERSION = '2026-09-04';

    /**
     * Booking terms, by service.
     *
     * @return array<string, array{title: string, intro: string, clauses: list<string>}>
     */
    public static function bookingTerms(): array
    {
        return [
            'general' => [
                'title' => 'Terms that apply to everything',
                'intro' => 'These apply to every service PISFA provides, whether it was arranged on this website, over the telephone, or on WhatsApp.',
                'clauses' => [
                    'Your contract is with '.config('pisfa.company.legal_name', 'PISFA Tour and Travel Limited').', a private company limited by shares incorporated in Uganda under the Companies Act 2012, registration number '.config('pisfa.company.registration_number').', with its place of business at '.config('pisfa.company.address').'. "PISFA", "we" and "us" in these terms mean that company, which trades as '.config('pisfa.company.name').'.',
                    'A request is not a booking. Submitting a form, sending a message or receiving a quotation does not reserve anything. A booking exists only when PISFA confirms it in writing and any required deposit has been received.',
                    'A quotation is valid for '.(int) config('pisfa.billing.quotation_validity_days', 14).' days from the date it is issued, unless it states otherwise on its face. After that, prices are re-checked.',
                    'Prices are quoted in Uganda Shillings or United States Dollars, as stated on the quotation or invoice, and each document is settled in the currency it was issued in. Amounts are never converted between currencies without a written agreement to do so.',
                    'Where '.config('pisfa.billing.tax_label', 'VAT').' applies it is shown separately on the quotation and the invoice at the rate in force on the date of issue, and that stamped rate is what governs the document even if the rate later changes.',
                    'Invoices are payable within '.(int) config('pisfa.billing.invoice_terms_days', 14).' days of issue unless the invoice states a different date. Services may be suspended on overdue accounts.',
                    'The customer is responsible for the accuracy of the names, dates, passport and permit details, and contact information they give us. Corrections after confirmation may attract supplier charges, which are passed on at cost.',
                    'Every traveller is responsible for their own visas, entry permits, vaccinations and travel insurance. PISFA will advise on request but does not obtain these on a customer\'s behalf unless expressly engaged to do so in writing.',
                    'PISFA strongly recommends comprehensive travel insurance covering medical treatment, evacuation, cancellation and personal belongings. PISFA is not an insurer and does not provide cover.',
                    'PISFA is not liable for delay or failure caused by events beyond its reasonable control, including weather, flooding, impassable roads, civil unrest, strike action, national park closures, government direction, or the failure of an independent supplier despite reasonable care in selecting them.',
                    'Nothing in these terms limits liability for death or personal injury caused by negligence, for fraud, or for anything else that cannot be limited under the law of Uganda.',
                    'These terms are governed by the laws of Uganda, and the courts of Uganda have jurisdiction over any dispute arising from them.',
                    'Complaints should be raised with PISFA as soon as possible, and while the service is still running wherever that is practical — a problem with a vehicle or a room can usually be fixed on the spot, and rarely can be fixed afterwards.',
                ],
            ],

            'tours' => [
                'title' => 'Tours and safaris',
                'intro' => 'Guided safaris, cultural journeys, day trips and multi-day itineraries.',
                'clauses' => [
                    'Tour prices include the items listed under "What is included" on the tour page, and exclude everything listed as excluded. Where an itinerary is published, it is the plan rather than a guarantee.',
                    'Itineraries may change where wildlife movement, road conditions, weather, park regulations or safety require it. PISFA will provide the nearest reasonable alternative and will tell the customer as soon as it is known.',
                    'Gorilla and chimpanzee permits are issued by the Uganda Wildlife Authority in the traveller\'s name, are strictly limited in number, and are ordinarily non-refundable and non-transferable once purchased. Where a permit has been bought for a customer, its cost is not refundable even if the rest of a booking is cancelled.',
                    'A tour may be cancelled by the customer up to '.(int) config('pisfa.tours.cancellation_cutoff_hours', 48).' hours before departure through their account or by contacting PISFA. Charges for cancellation are set out in the cancellation policy.',
                    'Where a departure has a minimum number of travellers, PISFA may cancel it if that number is not reached, and will offer an alternative date or a full refund of everything paid to PISFA for that departure.',
                    'Travellers must follow the guide\'s instructions on safety, wildlife distance and park rules. PISFA may end a person\'s participation without refund where their conduct endangers themselves, other travellers, staff or wildlife.',
                    'Travellers must be honest about relevant medical conditions and mobility at the time of booking, so that a realistic itinerary can be planned. Some parks and trekking activities are physically demanding.',
                ],
            ],

            'car-hire' => [
                'title' => 'Car hire',
                'intro' => 'Self-drive and chauffeur-driven vehicle hire. These clauses also form part of the rental agreement signed at handover.',
                'clauses' => [
                    'Hire is charged per day. A day is a twenty-four hour period from the agreed collection time. A late return of more than one hour is charged as a further full day unless agreed in writing beforehand.',
                    'The minimum notice for a hire is '.(int) config('pisfa.car_hire.minimum_notice_hours', 2).' hours, and the maximum hire period arranged online is '.(int) config('pisfa.car_hire.maximum_days', 90).' days. Longer hires are arranged directly and may be treated as a lease.',
                    'For self-drive hire the driver must hold a valid driving permit held for at least two years, and must present the original permit and a national identity document or passport at collection. Copies uploaded in advance speed up handover; they do not replace the originals.',
                    'Only drivers named on the rental agreement may drive the vehicle. Allowing an unnamed person to drive voids insurance cover and makes the hirer responsible for the full value of any loss.',
                    'A refundable security deposit is taken at or before handover. It is returned after the vehicle is inspected on return, less the cost of any damage, missing fuel, traffic fines, or charges owed. The deposit is not the limit of the hirer\'s liability.',
                    'The vehicle is supplied with a recorded fuel level and must be returned at the same level. Any shortfall is charged at the pump price plus a refuelling charge.',
                    'Traffic offences, Express Penalty Scheme tickets and parking charges incurred during the hire are the hirer\'s responsibility, including those that reach PISFA after the vehicle has been returned.',
                    'The vehicle must not be driven outside Uganda without prior written consent and the correct cross-border documentation, must not be used for racing, towing beyond its rating, or driving instruction, and must not be driven by anyone under the influence of alcohol or drugs.',
                    'The hirer must report any accident, theft or damage to PISFA and to the Uganda Police immediately, and must obtain a police reference. Insurance claims are routinely refused without a police report.',
                    'Where insurance applies, the hirer remains responsible for the excess stated in the rental agreement. Damage to tyres, windscreen and the underside of the vehicle, and any damage caused by a breach of these terms, may not be covered at all.',
                    'PISFA maintains its vehicles and will replace a vehicle that becomes unserviceable through no fault of the hirer, as soon as it reasonably can. Where no replacement is possible, unused hire days are refunded.',
                    'A hire may be cancelled up to '.(int) config('pisfa.car_hire.cancellation_cutoff_hours', 48).' hours before collection. Charges for cancellation are set out in the cancellation policy.',
                    'For chauffeur-driven hire, the driver\'s legal hours, rest breaks and accommodation on overnight trips are included in the quoted rate unless the quotation says otherwise.',
                ],
            ],

            'airport-transfers' => [
                'title' => 'Airport transfers',
                'intro' => 'Pickups and drop-offs at Entebbe International Airport and point-to-point transfers.',
                'clauses' => [
                    'A transfer must be requested at least '.(int) config('pisfa.airport_transfers.minimum_notice_hours', 2).' hours before pickup, and no more than '.(int) config('pisfa.airport_transfers.maximum_advance_days', 365).' days ahead.',
                    'The customer must give the correct flight number and scheduled arrival time. PISFA monitors arrivals and adjusts to a delayed flight at no extra charge where the flight number was supplied.',
                    'Waiting time is included for sixty minutes after an international flight lands and thirty minutes after a domestic flight or a scheduled pickup time. Waiting beyond that is charged at the published waiting rate.',
                    'A no-show — where the passenger cannot be found, does not make contact, and the included waiting time has passed — is charged in full.',
                    'The quoted price covers the passengers and luggage stated in the booking, up to '.(int) config('pisfa.airport_transfers.maximum_passengers', 50).' passengers and '.(int) config('pisfa.airport_transfers.maximum_luggage', 100).' pieces. Additional passengers or oversized luggage may require a larger vehicle at a different price.',
                    'A transfer may be cancelled without charge up to '.(int) config('pisfa.airport_transfers.cancellation_cutoff_hours', 4).' hours before the pickup time.',
                    'Child seats are provided on request and must be asked for at the time of booking.',
                ],
            ],

            'accommodation' => [
                'title' => 'Accommodation and stays',
                'intro' => 'Lodges, hotels and longer-stay properties booked through PISFA.',
                'clauses' => [
                    'Check-in and check-out times are set by each property and are shown on its page. Early check-in and late check-out are subject to availability and may be charged.',
                    'The lead guest must be at least eighteen years old and present identification at check-in.',
                    'Each property sets its own free-cancellation window, which is shown before booking and repeated on the confirmation. Cancelling inside that window may attract a charge of up to the full first night, or more where the property\'s own terms require it.',
                    'The number of guests must not exceed the occupancy stated for the room. Additional guests may be refused entry or charged.',
                    'Guests are responsible for damage they or their party cause to a property beyond fair wear and tear.',
                    'Where PISFA books a room on a customer\'s behalf at a third-party property, that property\'s own house rules and cancellation terms apply in addition to these, and PISFA will make them available on request.',
                ],
            ],

            'vehicle-imports' => [
                'title' => 'Vehicle imports',
                'intro' => 'Sourcing, shipping, clearing and delivering an imported vehicle.',
                'clauses' => [
                    'An import quotation separates the cost of the vehicle from freight, insurance, duties, taxes and PISFA\'s service fee, so the customer can see what is being paid to whom.',
                    'Duties and taxes are assessed by the Uganda Revenue Authority on the value and age of the vehicle at the time it is cleared, not at the time it is quoted. Where an assessment exceeds the estimate, the difference is payable by the customer, and PISFA will provide the assessment document.',
                    'Shipping schedules are set by carriers and ports. Estimated arrival dates are estimates. PISFA is not liable for losses arising from a delayed sailing, port congestion, or a customs inspection.',
                    'Uganda restricts the import of vehicles above a certain age and applies an environmental levy. PISFA will advise on the rules in force at the time of enquiry, and it is the customer\'s decision whether to proceed.',
                    'Once a vehicle has been purchased at auction or from a supplier on the customer\'s instruction, that purchase cannot be reversed. Sums already committed are not refundable.',
                    'Risk in the vehicle passes to the customer on delivery. Marine insurance is strongly recommended and is quoted separately.',
                    'PISFA inspects the vehicle on arrival and reports any damage found. Claims for shipping damage are made against the carrier or the marine insurer, and PISFA will assist with the paperwork.',
                ],
            ],

            'vehicle-sales' => [
                'title' => 'Vehicles for sale',
                'intro' => 'Vehicles sold from the PISFA showroom.',
                'clauses' => [
                    'Every vehicle is sold as seen. Buyers are encouraged to inspect the vehicle and to arrange an independent mechanical inspection before committing.',
                    'Mileage, year and specification are stated in good faith from the vehicle\'s documents. Buyers should verify anything that matters to their decision.',
                    'A used vehicle is sold without warranty unless a written warranty is issued with it, in which case that document sets out what is covered and for how long.',
                    'A vehicle is reserved only once a deposit is received. Deposits are applied to the purchase price and are not refundable where a buyer withdraws, unless PISFA has misdescribed the vehicle.',
                    'Ownership transfers when the full price is paid and cleared. Transfer of the logbook through the Uganda Revenue Authority is completed after that, and the associated fees are the buyer\'s unless the sale agreement says otherwise.',
                    'The buyer becomes responsible for insurance and for the vehicle itself from the moment it leaves PISFA\'s premises.',
                ],
            ],

            'vehicle-leasing' => [
                'title' => 'Leasing your vehicle to PISFA',
                'intro' => 'Placing a privately owned vehicle into the PISFA fleet on agreed terms.',
                'clauses' => [
                    'A separate written lease agreement governs each vehicle. These clauses are the general position, and the signed lease is what binds the parties.',
                    'The owner must prove ownership, and the vehicle must be free of any outstanding finance or lien unless the financier consents in writing.',
                    'The vehicle must be roadworthy, currently insured and correctly licensed at the start of the lease, and must pass PISFA\'s inspection.',
                    'The lease agreement states who pays for insurance, routine servicing, tyres and repairs. Read that allocation carefully: it is the clause that most often surprises owners.',
                    'Payouts are made on the schedule in the lease agreement, against a statement showing the vehicle\'s earnings and any deductions for the period.',
                    'PISFA is responsible for damage caused by its own drivers during the lease, subject to the insurance arrangements set out in the agreement. Fair wear and tear is not damage.',
                    'Either party may end the lease on the notice stated in the agreement. The vehicle is returned in the condition it was supplied, allowing for fair wear and tear.',
                ],
            ],
        ];
    }

    /**
     * Cancellation and refund policy.
     *
     * @return array{intro: list<string>, tiers: array<string, array{service: string, rows: list<array{when: string, charge: string}>}>, notes: list<string>}
     */
    public static function cancellationPolicy(): array
    {
        return [
            'intro' => [
                'Plans change. This policy sets out what a cancellation costs, so that nobody has to guess and nobody is surprised.',
                'The principle behind it is simple: the closer to departure a cancellation comes, the more PISFA has already committed and cannot recover. Sums already paid to a park authority, an airline, a lodge or a supplier are not refundable to PISFA either, and so cannot be refunded onwards.',
                'All cancellations must be made in writing — through your account, by email, or on WhatsApp. The date PISFA receives the cancellation is the date used.',
            ],

            'tiers' => [
                'tours' => [
                    'service' => 'Tours and safaris',
                    'rows' => [
                        ['when' => 'More than 30 days before departure', 'charge' => 'Deposit retained; everything else refunded'],
                        ['when' => '15 to 30 days before departure', 'charge' => '50% of the total'],
                        ['when' => '8 to 14 days before departure', 'charge' => '75% of the total'],
                        ['when' => 'Within '.(int) config('pisfa.tours.cancellation_cutoff_hours', 48).' hours of departure, or no-show', 'charge' => '100% of the total'],
                    ],
                ],
                'car-hire' => [
                    'service' => 'Car hire',
                    'rows' => [
                        ['when' => 'More than '.(int) config('pisfa.car_hire.cancellation_cutoff_hours', 48).' hours before collection', 'charge' => 'No charge; any deposit refunded in full'],
                        ['when' => 'Within '.(int) config('pisfa.car_hire.cancellation_cutoff_hours', 48).' hours of collection', 'charge' => 'One day of hire'],
                        ['when' => 'No-show, or cancellation after collection time', 'charge' => 'One day of hire, and the booking is released'],
                        ['when' => 'Early return of a confirmed hire', 'charge' => 'Unused full days refunded at the daily rate; part days are not refunded'],
                    ],
                ],
                'airport-transfers' => [
                    'service' => 'Airport transfers',
                    'rows' => [
                        ['when' => 'More than '.(int) config('pisfa.airport_transfers.cancellation_cutoff_hours', 4).' hours before pickup', 'charge' => 'No charge'],
                        ['when' => 'Within '.(int) config('pisfa.airport_transfers.cancellation_cutoff_hours', 4).' hours of pickup', 'charge' => '50% of the fare'],
                        ['when' => 'No-show after the included waiting time', 'charge' => '100% of the fare'],
                    ],
                ],
                'accommodation' => [
                    'service' => 'Accommodation',
                    'rows' => [
                        ['when' => 'Outside the property\'s free-cancellation window', 'charge' => 'No charge'],
                        ['when' => 'Inside that window', 'charge' => 'Up to the first night, or the property\'s own charge if higher'],
                        ['when' => 'No-show', 'charge' => 'As set by the property, up to the full stay'],
                    ],
                ],
            ],

            'notes' => [
                'Gorilla and chimpanzee permits are never refundable once purchased, whatever the notice given, because the Uganda Wildlife Authority does not refund them. This is separate from, and in addition to, the table above.',
                'Airline tickets, park entry bought in advance, and third-party bookings follow the supplier\'s own rules, which PISFA will provide on request.',
                'Where PISFA cancels — because a minimum number was not reached, a vehicle could not be replaced, or for any other reason within its control — the customer receives a full refund of everything paid to PISFA, or an alternative date if they prefer one.',
                'Refunds are made to the account the payment came from, in the currency it was paid in, within fourteen days of the refund being agreed. Bank and mobile-money charges on the return are borne by PISFA.',
                'Where a trip is prevented by something outside both parties\' control — a national park closing, a road becoming impassable, a government direction — PISFA will refund whatever it can recover from its suppliers and will not charge its own fee. It cannot refund what it has already paid away and cannot get back.',
                'PISFA may waive a cancellation charge in genuine hardship, such as serious illness or bereavement, at its discretion. Ask.',
            ],
        ];
    }

    /**
     * The rental agreement clauses stamped into each car hire contract.
     *
     * Drawn from the same car-hire terms shown publicly, so what a customer
     * signs at handover is what the website told them it would be.
     *
     * @return list<string>
     */
    public static function rentalAgreementClauses(): array
    {
        return self::bookingTerms()['car-hire']['clauses'];
    }
}
