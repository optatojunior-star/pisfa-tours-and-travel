# Legal documents

## Where they live

`app/Support/LegalDocuments.php` holds the booking terms and the cancellation
policy as structured data. Three things read it:

- `/booking-terms` — the public terms page, by service
- `/cancellation-policy` — the public cancellation and refund policy
- `CreateCarHireBooking` — the clauses stamped onto every rental agreement

That single source is deliberate. A cancellation window quoted on the website
and a different one printed on the contract is the kind of contradiction a
customer finds at exactly the wrong moment, and it is the failure this
arrangement makes impossible.

Every figure in the text is read from configuration — `config/pisfa.php` and
`config/car_hire.php` — rather than typed into the prose. So the notice period
on the page is the same integer the booking code enforces. `MarketingLegalPagesTest`
asserts that, because a policy the software will break is worse than no policy.

## The company

The certificate of incorporation records:

- **Registered name:** PISFA Tour and Travel Limited (private company limited by shares)
- **Registration number:** 80034149987265
- **Incorporated:** 10 April 2026, Kampala, under the Companies Act 2012

The registered name is *not* the trading name. Customers know the business as
"PISFA Tours and Travels", and marketing uses that; invoices, quotations,
contracts and the terms carry the registered name and number, as a limited
company's documents must. Both are in `config/pisfa.php` —
`company.name` and `company.legal_name` — and `SettingsRepository::brand()`
exposes both so a document can pick the right one.

`PISFA_COMPANY_TIN` is still blank. Fill it in once URA has issued one; until
then the footer simply omits it rather than printing a placeholder.

## Before this binds anyone

**These are drafts, and they have not been reviewed by a Ugandan advocate.**

They were written to match the services PISFA actually runs and the rules the
software actually enforces, which makes them a solid starting point and not a
substitute for advice. The clauses most likely to need changing are the ones
that decide what happens when something goes wrong:

- **Cancellation charges** — the percentages in `cancellationPolicy()` are
  conventional for the region, not derived from PISFA's own cost structure.
  Check them against what a cancellation actually costs the business.
- **Insurance and excess** — the car hire clauses describe an excess and a list
  of exclusions (tyres, windscreen, underside). These must match the actual
  motor policy. If they do not, the contract promises cover that does not exist.
- **Liability limits** — the force majeure and limitation clauses need checking
  against Ugandan consumer law.
- **Data protection** — the privacy policy is written against the Data
  Protection and Privacy Act 2019 and names the Personal Data Protection Office.
  Confirm whether PISFA must register as a data collector, and the retention
  period stated for tax records.
- **Vehicle imports** — the URA duty and age-limit wording changes with the
  budget. Re-check each year.

Have an advocate read it, then update `LegalDocuments::LAST_UPDATED` and
`VERSION`. Contracts snapshot the version they were accepted under, so changing
the text never rewrites an agreement somebody has already signed.
