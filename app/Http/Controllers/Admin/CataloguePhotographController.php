<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Documents\DeleteDocument;
use App\Contracts\HasPhotographs;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\TourPackage;
use App\Models\TourPackageMedia;
use App\Models\Vehicle;
use App\Models\VehicleMedia;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Removing a photograph from a catalogue record.
 *
 * A picture exists in two places — the stored Document and the small media row
 * the public catalogue reads. Deleting only one of them is what "removed" used
 * to mean everywhere it was implemented by hand: either the file lingered on
 * disk with nothing pointing at it, or the card kept showing a picture that was
 * no longer there. Both go, in one transaction, or neither does.
 *
 * Until this existed there was no way to remove a photograph at all. The upload
 * component was given a null delete route, so a wrong picture stayed up until
 * somebody edited the database.
 */
class CataloguePhotographController extends Controller
{
    public function destroyTourPhotograph(
        Request $request,
        TourPackage $tourPackage,
        TourPackageMedia $medium,
        DeleteDocument $action,
    ): RedirectResponse {
        $this->authorize('update', $tourPackage);

        return $this->remove($request, $tourPackage, $medium, $action);
    }

    public function destroyVehiclePhotograph(
        Request $request,
        Vehicle $vehicle,
        VehicleMedia $medium,
        DeleteDocument $action,
    ): RedirectResponse {
        $this->authorize('update', $vehicle);

        return $this->remove($request, $vehicle, $medium, $action);
    }

    /**
     * @param  HasPhotographs&Model  $owner  the record the picture belongs to
     * @param  Model  $medium  its row in that record's media table
     */
    private function remove(
        Request $request,
        HasPhotographs&Model $owner,
        Model $medium,
        DeleteDocument $action,
    ): RedirectResponse {
        $url = (string) ($medium->getAttribute('url') ?? '');
        $wasCover = (bool) $medium->getAttribute('is_cover');

        DB::transaction(function () use ($request, $owner, $medium, $action, $url, $wasCover): void {
            // The media row holds the URL the Document produced, which is the
            // only link back. Matching on it can find nothing — a row imported
            // before uploads existed points at somebody else's server — and
            // that is not an error: the row still goes.
            $document = $owner->photographs()
                ->get()
                ->first(static fn (Document $candidate): bool => $candidate->url() === $url);

            if ($document instanceof Document) {
                $action->execute($request->user(), $document, 'Removed from the catalogue.');
            }

            $medium->delete();

            // A record is never left with photographs but no cover, which would
            // show an empty card on the public catalogue.
            if ($wasCover) {
                $owner->media()->orderBy('sort_order')->orderBy('id')->first()?->update(['is_cover' => true]);
            }
        }, 3);

        return back()->with('success', 'Photograph removed.');
    }
}
