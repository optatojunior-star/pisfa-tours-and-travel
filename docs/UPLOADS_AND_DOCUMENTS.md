# Uploads and Generated Documents (F27)

One system handles every file in PISFA: uploads from people and PDFs generated
by the application. Domains attach documents polymorphically instead of each
growing its own table, upload rules, and download route.

## Threat model

Uploads are the highest-risk input surface in the product. Two things are
attacker-controlled and therefore never trusted:

- the **filename**, and
- the browser-supplied **Content-Type**.

`Services\Documents\FileInspector` is the only gate, and it runs **before**
anything reaches disk:

| Check | How |
|---|---|
| Real type | `finfo` reads the file's own bytes. The declared Content-Type is ignored entirely. |
| Magic bytes | Header compared against known signatures — a second opinion if `finfo`'s database is stale or patched. |
| Embedded code | First 8 KB scanned for `<?php`, `<?=`, `<script`, `#!/`, `<%`. Catches the polyglot that is a valid JPEG *and* a valid script. |
| Extension | Must match the **detected** type. A real PNG renamed `.pdf` is refused. |
| Size | Per-kind limit from config. |
| Image dimensions | Minimum enforced; maximum enforced too, because an enormous declared canvas is a decompression-bomb vector. |
| Emptiness | Zero-byte files refused. |

The stored filename is **generated** — `bin2hex(random_bytes(16))` plus the
canonical extension. Traversal, null bytes, double extensions, and unicode
homographs all stop being possible when no part of the name comes from input.

Storage paths are additionally normalised in `DocumentStorage`, so `..` and
absolute segments cannot appear even if a future caller passes something odd.

Every one of these rules has an adversarial test in
`tests/Feature/Documents/FileInspectorTest.php`, including a PHP script renamed
`.jpg` and a JPEG-magic-bytes polyglot with `<?php system('id'); ?>` in its body.

## Visibility is decided by category, not by the caller

`DocumentCategory::visibility()` is authoritative. A caller cannot ask for an
identity document to be public:

- **Private** — identity documents, driving permits, applicant photos, import
  paperwork, insurance, registrations, expense receipts, and every generated
  contract, quotation, invoice, payslip, receipt, and statement.
- **Public** — vehicle, property, blog, and review media only.

A private document has **no URL**. `Document::url()` returns `null` for it by
design. It is reachable only through:

1. `documents.show` — session-authenticated, policy-checked; or
2. `documents.signed` — a short-lived signed link for a recipient with no
   account, which additionally refuses to serve a public document so it cannot
   become a second path to everything.

## Authorization inherits from the subject

`DocumentPolicy` has no ownership rules of its own. It asks the owning record's
policy: *if you may view the booking, you may view its contract.* One rule per
domain, no drifting second copy.

Two deliberate exceptions:

- A **generated** document (contract, invoice, receipt) can only be deleted by
  operations. It is financial evidence, not customer self-service.
- An **orphaned** document — owner deleted — falls back to administration only,
  rather than becoming universally readable.

## Versioning

`DocumentCategory::isVersioned()` decides what replacement means:

| Behaviour | Categories | Rationale |
|---|---|---|
| **Supersede and keep** | contracts, quotations, invoices, statements, insurance, registrations | A superseded contract may already have been sent, signed, or paid against. Destroying it destroys evidence. |
| **Replace and purge** | applicant photo, identity document, and other single-slot uploads | The customer replaced it; keeping the old personal data serves nobody. |

Exactly one revision per `(owner, category)` is `is_current`, and
`(documentable_type, documentable_id, category, version)` is unique in the
database — not merely enforced by application discipline. Version numbers
continue past deleted revisions, so history never misleads.

## Deletion

`DeleteDocument` soft-deletes the row and **destroys the bytes**. The audit
trail keeps what existed and who removed it; the personal data does not survive.
Retaining an identity document after a deletion request is precisely what the
customer asked us not to do.

## Failure atomicity

The write order is: inspect → write file → open transaction → insert row.

- If validation fails, nothing was written.
- If the transaction fails, the orphaned file is deleted in the `catch`.
- Superseded files are purged only in `DB::afterCommit`, so a rollback can never
  leave a surviving row pointing at a deleted file.

Covered by `test_a_rejected_upload_leaves_no_orphaned_file_or_row`.

## Generated PDFs

`Services\Documents\PdfRenderer` renders Blade to PDF and files the result as a
versioned document.

**DomPDF** was chosen because Hostinger Premium is shared hosting: it is pure
PHP and needs no Chrome binary or system package, unlike Browsershot or
wkhtmltopdf. The trade-off is a limited CSS subset — `resources/views/pdf/`
therefore uses tables and simple styles deliberately, not out of neglect.
Flexbox and grid silently mis-render.

`render()` returns bytes without storing, for previews and on-demand downloads.
`store()` files a new version and writes an audit entry.

### Live today

- **Rental contract** (`pdf/car-hire-contract.blade.php`) — wired into the
  customer portal at `portal.car-hire-bookings.contracts.download`, rendered
  from the same immutable snapshot as the HTML view so the two cannot disagree.
  This closes the standing F04 gap where contracts were HTML only.

### Still to build (with their features)

Lease contracts (F10), quotations and invoices (F15), payslips (F24), payment
receipts (F14), statements (F10/F15).

## Storage backends

Disks are selected by visibility from `config/documents.php`:

```
PISFA_PRIVATE_DISK=local     # storage/app/private — never web-reachable
PISFA_PUBLIC_DISK=public     # storage/app/public  — symlinked
```

Laravel's filesystem already abstracts local and S3-compatible backends, so
switching `PISFA_PRIVATE_DISK` to `s3` changes nothing above `DocumentStorage`.
Cloudinary requires a driver package and is **not yet wired** — see
`docs/INTEGRATIONS.md`.

Downloads stream rather than loading into memory, so a large PDF cannot exhaust
the modest memory limit on shared hosting. Private responses carry
`Cache-Control: private, no-store, max-age=0` and `X-Content-Type-Options:
nosniff`, and the download filename is derived from the category and id — never
from the uploaded name.

## Configuration

```
PISFA_PRIVATE_DISK=local
PISFA_PUBLIC_DISK=public
PISFA_IMAGE_MAX_KB=5120
PISFA_FILE_MAX_KB=10240
PISFA_SIGNED_URL_MINUTES=15
PISFA_PDF_PAPER=a4
PISFA_COMPANY_NAME="PISFA Tours and Travels"
PISFA_COMPANY_ADDRESS="Kampala, Uganda"
PISFA_COMPANY_REGISTRATION=
PISFA_COMPANY_TIN=
```

## Tests

`tests/Feature/Documents/` — 37 tests:

- `FileInspectorTest` (11) — adversarial content checks.
- `DocumentLifecycleTest` (10) — storage, visibility forcing, generated
  filenames, versioning vs replacement, atomicity, deletion, owner scoping.
- `DocumentDownloadAuthorizationTest` (10) — guest, cross-customer, suspended
  account, signature validity and expiry, cache headers, missing file, public
  document refused by the signed route, filename sanitisation.
- `PdfGenerationTest` (6) — real PDF bytes, versioned filing, supersession,
  authorized download, deletion restriction.

Plus `tests/Feature/CarHire/ContractPdfDownloadTest.php` (7) for the live
portal route.

## Outstanding

- **Cloudinary adapter** not wired.
- **Existing car-hire documents** still use the older per-domain
  `car_hire_documents` table and its own upload action. It is secure and tested,
  but it should migrate onto this system so there is one path, not two.
- Remaining PDF generators arrive with their features.
- No virus scanning. Worth considering if Hostinger exposes ClamAV.
