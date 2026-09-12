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

## `GalleryImage`, and where the home-page gallery lives

Public, a collection, and image-only. The photographs are marketing, so they sit
on the public disk with direct URLs and cache like any other picture on the site
— the opposite call from `DriverPhoto` below, and for the opposite reason.

They need an owner, because documents are polymorphic and `documentable` is not
nullable. `MediaAlbum::homeGallery()` supplies one: the album mechanism already
exists to be "a real record standing in for no particular thing", so the gallery
reuses it rather than introducing a second owner type. The slug `home-gallery`
is reserved, and `firstOrCreate` on a unique column means two people uploading
at once get one album rather than a duplicate-key error for whoever was second.

Captions go in the document's `metadata` bag — one per file, belonging to the
file, and `Document` already carries JSON metadata for exactly this. Order is
`sort_order`, ascending, which `HandlesImageUploads` increments per upload, so
adding a photograph never reshuffles the ones already arranged.

`PublicPageController::home()` reads the album with `where('slug', ...)->first()`
rather than calling `homeGallery()`: the public site must never create a record
just by being viewed.

## A note on `DriverPhoto`

It is the one image category that looks like public media and is not.

`TeamPhoto` is marketing: the person chose to appear on the About page, and the
file sits on the public disk with a direct URL. A driver's headshot exists for a
single moment — a customer in arrivals at Entebbe deciding whether the man
walking towards them is the one PISFA sent. That is a good reason to show it to
that customer and no reason to leave a staff member's face at a public URL that
outlives the trip.

So it is private, and reaches the confirmation page as a short-lived signed link
minted per render for a viewer already authorized against the booking
(`DriverProfile::photographUrl()`). The authorized route refuses it to anyone
but administration, because `DriverProfile` has no policy and `DocumentPolicy`
falls back to `canAccessAdministration()` — which is what stops a customer
reaching another customer's driver by incrementing a document id.

It is also not a collection: a driver has one face, and a gallery would leave
years of superseded headshots on a shared host. Drivers manage it themselves at
`drivers.photograph.store`; there is no admin screen for driver profiles to put
it on, and it is their face.

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

## Photographs are shrunk in the browser before they are uploaded

Saving a tour with a full set of photographs returned **504 Gateway Timeout** in
production. Nothing on the server was slow: a photograph off a modern phone is
3–6 MB at around 4000×3000, twelve of them is sixty megabytes of request body,
and on a domestic Ugandan upstream that takes minutes. The proxy in front of PHP
gave up first — after the uploader had already spent the data, with nothing
saved.

`resources/views/components/image-upload.blade.php` now redraws anything over the
threshold onto a canvas no larger than the configured longest edge before the
form is submitted. At 1920px — wider than anywhere the site displays a
photograph — the same twelve pictures come to roughly 4 MB and the upload
finishes in seconds.

Rules the component follows:

- JPEG and WebP are re-encoded in their own format at 0.82/0.85 quality. PNG is
  re-encoded as PNG, so a logo with transparency does not come back on a black
  ground.
- The file name and extension are preserved, because `FileInspector` reads the
  real bytes and then checks the extension agrees with them.
- A file already under `PISFA_IMAGE_BROWSER_SHRINK_OVER_KB` is left untouched
  rather than re-encoded for no gain.
- A re-encode that came out larger is discarded.
- If `createImageBitmap`, `canvas.toBlob` or `DataTransfer` is missing, or a file
  fails to decode, the original uploads exactly as before. The server's limits
  are unchanged and remain the real boundary.

Every upload screen uses this one component — tours, vehicles, showroom,
accommodation, journal, team and service pictures, and the image library. The
image library and the service-pictures screen each had a hand-rolled copy of the
drop zone; those were the two places that never got the shrinking, and the image
library takes twenty files at a time, so it was the likeliest place in the
console to time out.

Pass `:shrink="false"` to opt a form out, or `:max-edge="640"` to cap it lower —
service pictures do, because they are rendered as ~48px tiles.

## PHP limits

`public/.user.ini` is committed and applies to the whole document root, since
`public_html` is a symlink to `public/`. It is kept in git deliberately:
changing an upload limit should be a deploy, not an SSH session with an editor.
`public/.htaccess` denies HTTP access to it.

`max_input_time` is the one that matters for a large upload on a slow link and
is the one most often left at 60 seconds. `max_execution_time` does not cover
time spent *receiving* the body.

A 504 is returned by the proxy, not by PHP, so it can appear with nothing in
`storage/logs`. If one is reported, check the request size before looking for an
application bug.

## Configuration

```
PISFA_PRIVATE_DISK=local
PISFA_PUBLIC_DISK=public
PISFA_IMAGE_MAX_KB=5120
PISFA_IMAGE_BROWSER_EDGE=1920
PISFA_IMAGE_BROWSER_SHRINK_OVER_KB=900
PISFA_FILE_MAX_KB=10240
PISFA_SIGNED_URL_MINUTES=15
PISFA_PDF_PAPER=a4
PISFA_COMPANY_NAME="PISFA Tours and Travels"
PISFA_COMPANY_ADDRESS="Kampala, Uganda"
PISFA_COMPANY_REGISTRATION=
PISFA_COMPANY_TIN=
```

`PISFA_IMAGE_BROWSER_EDGE` raises upload time roughly with its square. Raise it
only if a photograph is genuinely being displayed larger than 1920px somewhere.

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
