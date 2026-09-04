<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Documents\DeleteDocument;
use App\Enums\DocumentCategory;
use App\Http\Controllers\Concerns\HandlesImageUploads;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\ServiceImage;
use App\Support\ServiceCatalogue;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * A picture of your own for each service.
 *
 * The line icons are consistent and they are also generic — a stroked car is
 * every hire company's stroked car. A photograph of an actual vehicle on an
 * actual Ugandan road is not, and it is the sort of thing that should not need
 * a developer to change.
 *
 * ServiceCatalogue still owns what the services *are*. This only says what each
 * one looks like, and a service with no picture quietly keeps its icon rather
 * than showing a gap.
 */
class ServiceImageController extends Controller
{
    use HandlesImageUploads;

    public function index(): View
    {
        $this->authorize('viewAny', Document::class);

        return view('admin.service-images.index', [
            'services' => ServiceCatalogue::SERVICES,
            'rows' => ServiceImage::keyedByService(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Document::class);

        $validated = $request->validate([
            'service_key' => ['required', 'string', Rule::in(array_keys(ServiceCatalogue::SERVICES))],
        ] + $this->imageRules('images', 1), $this->imageMessages());

        // firstOrCreate on a unique column: two people uploading at once get
        // one row, not a duplicate-key error on whoever was second.
        $service = ServiceImage::query()->firstOrCreate(
            ['service_key' => $validated['service_key']],
            ['updated_by_user_id' => $request->user()?->getKey()],
        );

        // One picture per service. The previous one goes, or the newest-wins
        // rule would quietly leave every superseded upload on the public disk.
        $this->clearExisting($request, $service);

        $rejected = $this->storeUploadedImages($request, $service, DocumentCategory::ServiceIcon);

        $service->forceFill(['updated_by_user_id' => $request->user()?->getKey()])->save();

        return $this->withRejectedImages(
            back()->with('success', ServiceCatalogue::SERVICES[$validated['service_key']]['name'].' now uses your own picture.'),
            $rejected,
        );
    }

    /** Back to the line icon. */
    public function destroy(Request $request, ServiceImage $serviceImage): RedirectResponse
    {
        $this->authorize('create', Document::class);

        $this->clearExisting($request, $serviceImage);

        $name = ServiceCatalogue::SERVICES[$serviceImage->service_key]['name'] ?? 'The service';

        return back()->with('success', $name.' is back to its standard icon.');
    }

    private function clearExisting(Request $request, ServiceImage $service): void
    {
        $action = app(DeleteDocument::class);

        foreach ($service->photographs()->get() as $photograph) {
            $action->execute($request->user(), $photograph, 'Replaced by a newer service image.');
        }
    }
}
