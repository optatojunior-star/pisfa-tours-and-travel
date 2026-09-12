<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Documents\DeleteDocument;
use App\Enums\DocumentCategory;
use App\Http\Controllers\Concerns\HandlesImageUploads;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\MediaAlbum;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The sliding gallery on the home page.
 *
 * Service pictures answer "what does car hire look like"; these answer "is this
 * company worth trusting with my holiday". They are the wide shots — a sunrise
 * over the Nile, a full minibus at a park gate, a convoy on a murram road — and
 * there was nowhere to put one.
 *
 * Photographs are public by category, so they are served from the public disk
 * with direct URLs. That is the right call here and the wrong call for a driver
 * headshot; see DocumentCategory::visibility() for the distinction.
 */
class GalleryController extends Controller
{
    use HandlesImageUploads;

    public function index(): View
    {
        $this->authorize('viewAny', Document::class);

        $gallery = MediaAlbum::homeGallery();

        return view('admin.gallery.index', [
            'gallery' => $gallery,
            'images' => $gallery->galleryImages()->get(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorize('create', Document::class);

        $request->validate($this->imageRules('images', 12), $this->imageMessages());

        $gallery = MediaAlbum::homeGallery();
        $rejected = $this->storeUploadedImages($request, $gallery, DocumentCategory::GalleryImage);
        $added = count((array) $request->file('images', [])) - count($rejected);

        return $this->withRejectedImages(
            back()->with('success', $added === 1
                ? 'One photograph added to the home page gallery.'
                : $added.' photographs added to the home page gallery.'),
            $rejected,
        );
    }

    /**
     * The caption shown under the picture as it slides.
     *
     * Stored in the document's own metadata rather than a new column: a caption
     * belongs to the file, there is exactly one per file, and Document already
     * carries a JSON metadata bag for precisely this kind of detail.
     */
    public function caption(Request $request, Document $document): RedirectResponse
    {
        $this->authorize('create', Document::class);

        abort_unless($document->category === DocumentCategory::GalleryImage, 404);

        $validated = $request->validate([
            'caption' => ['nullable', 'string', 'max:160'],
        ]);

        $caption = trim((string) ($validated['caption'] ?? ''));

        /*
         * Assigned, not unioned.
         *
         * This was `($document->metadata ?? []) + ['caption' => $caption]`,
         * and PHP's array union keeps the *left* operand where keys collide —
         * so an existing caption silently won over the new one and could
         * neither be changed nor cleared. It looked right because the first
         * caption on a picture with no metadata worked perfectly.
         */
        $metadata = $document->metadata ?? [];
        $metadata['caption'] = $caption;

        // A blank caption is an absent caption, not an empty string sitting in
        // the JSON for the view to have to think about.
        $metadata = array_filter(
            $metadata,
            static fn (mixed $value): bool => $value !== '' && $value !== null,
        );

        $document->forceFill(['metadata' => $metadata === [] ? null : $metadata])->save();

        return back()->with('success', $caption === ''
            ? 'Caption removed.'
            : 'Caption saved.');
    }

    public function destroy(Request $request, Document $document, DeleteDocument $deleteDocument): RedirectResponse
    {
        $this->authorize('create', Document::class);

        abort_unless($document->category === DocumentCategory::GalleryImage, 404);

        $deleteDocument->execute($request->user(), $document, 'Removed from the home page gallery.');

        return back()->with('success', 'Photograph removed from the gallery.');
    }
}
