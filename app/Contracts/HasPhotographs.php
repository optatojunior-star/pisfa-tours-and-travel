<?php

namespace App\Contracts;

use App\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

/**
 * A record that keeps public photographs in its own media table.
 *
 * Two tables are involved and both are needed. An upload becomes a Document,
 * which is where the file inspection, the audit trail and the deletion rules
 * live; the public catalogue then reads a small media row holding the URL, the
 * caption and which picture is the cover. Uploading has to write both.
 *
 * Declaring that as an interface rather than repeating the bridge in every
 * controller means the shape is checked rather than assumed — and it is the
 * reason `photographs()` exists separately from `documents()`. On a vehicle,
 * `documents()` is insurance certificates and registration papers. Bridging
 * from that would copy a scan of a logbook into the public gallery.
 *
 * The relation generics are written `covariant` because each implementation
 * returns something narrower — VehicleMedia, TourPackageMedia — and Eloquent's
 * relation templates are invariant by default, which would reject exactly the
 * narrowing this interface exists to allow.
 */
interface HasPhotographs
{
    /** @return MorphMany<covariant Document, covariant Model> */
    public function photographs(): MorphMany;

    /** @return HasMany<covariant Model, covariant Model> */
    public function media(): HasMany;
}
