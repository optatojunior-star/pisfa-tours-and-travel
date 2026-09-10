<?php

namespace App\Http\Controllers\Admin;

use App\Actions\Documents\DeleteDocument;
use App\Actions\Documents\StoreDocument;
use App\Enums\DocumentCategory;
use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\MediaAlbum;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The image library.
 *
 * Every piece of machinery this needs already existed — StoreDocument,
 * FileInspector, DocumentStorage, the Document model with dimensions and a
 * content hash. What was missing was any way for a person to reach it: uploads
 * could only be created from code, which made the whole feature unusable from
 * the console.
 *
 * Images live on the public disk because they are meant to be shown on the
 * public site. Anything private — passport scans, signed contracts — keeps its
 * own category and its own visibility, and is deliberately not reachable here.
 */
class MediaLibraryController extends Controller
{
    /**
     * Categories a person may upload into from the library.
     *
     * Only public catalogue media. Identity documents and contracts are
     * attached to the record they belong to, through that record's own screen,
     * where the surrounding context makes the sensitivity obvious.
     */
    private const UPLOADABLE = [
        DocumentCategory::VehicleMedia,
        DocumentCategory::PropertyMedia,
        DocumentCategory::BlogMedia,
    ];

    public function index(Request $request): View
    {
        $this->authorize('viewAny', Document::class);

        $albumId = $request->query('album');
        $album = is_numeric($albumId) ? MediaAlbum::query()->find((int) $albumId) : null;

        $query = Document::query()
            ->whereIn('category', array_map(
                static fn (DocumentCategory $c): string => $c->value,
                self::UPLOADABLE,
            ))
            ->where('is_current', true)
            ->with('uploadedBy:id,name')
            ->latest('id');

        if ($album !== null) {
            $query->where('documentable_type', $album->getMorphClass())
                ->where('documentable_id', $album->getKey());
        }

        if (filled($request->query('q'))) {
            $query->where('original_name', 'like', '%'.$request->query('q').'%');
        }

        return view('admin.media.index', [
            'documents' => $query->paginate(24)->withQueryString(),
            'albums' => MediaAlbum::query()->withCount('images')->orderBy('name')->get(),
            'album' => $album,
            'search' => $request->query('q'),
            'categories' => self::UPLOADABLE,
            // The size limit is no longer passed down: x-image-upload reads it
            // from config itself, so there is one place it can be wrong.
        ]);
    }

    /**
     * Accepts one or more images.
     *
     * Nothing here validates the file itself: StoreDocument runs FileInspector,
     * which checks the real content rather than the extension — magic bytes,
     * embedded script, dimensions, size. A file that lies about what it is never
     * reaches the disk.
     */
    public function store(Request $request, StoreDocument $action): RedirectResponse
    {
        $this->authorize('create', Document::class);

        $maxKb = (int) config('documents.images.maximum_kilobytes', 5120);

        $validated = $request->validate([
            'images' => ['required', 'array', 'min:1', 'max:20'],
            'images.*' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.$maxKb],
            'album_id' => ['nullable', 'integer', 'exists:media_albums,id'],
        ], [
            'images.required' => 'Choose at least one image to upload.',
            'images.*.mimes' => 'Images must be JPG, PNG or WebP.',
            'images.*.max' => 'Each image must be under '.round($maxKb / 1024, 1).' MB.',
        ]);

        $album = isset($validated['album_id'])
            ? MediaAlbum::query()->findOrFail((int) $validated['album_id'])
            : MediaAlbum::defaultAlbum();

        /** @var User $actor */
        $actor = $request->user();
        $stored = 0;
        $rejected = [];

        foreach ($request->file('images') as $file) {
            try {
                $action->execute(
                    actor: $actor,
                    owner: $album,
                    category: DocumentCategory::BlogMedia,
                    file: $file,
                    metadata: ['album' => $album->name],
                );

                $stored++;
            } catch (ValidationException $e) {
                // One bad file must not discard the whole batch: somebody
                // selecting twenty holiday photos should not lose nineteen
                // because one was a screenshot saved as .jpg.
                $rejected[] = $file->getClientOriginalName().' — '
                    .collect($e->errors())->flatten()->first();
            }
        }

        $message = $stored === 1 ? '1 image uploaded.' : "{$stored} images uploaded.";

        if ($rejected !== []) {
            return back()
                ->with('success', $message)
                ->with('rejected', $rejected);
        }

        return back()->with('success', $message);
    }

    public function destroy(Request $request, Document $document, DeleteDocument $action): RedirectResponse
    {
        $this->authorize('delete', $document);

        $action->execute($request->user(), $document, 'Removed from the image library.');

        return back()->with('success', 'Image removed.');
    }

    /**
     * The picker, for choosing an existing image from another screen.
     *
     * Returns JSON so a form can drop it into a modal without a page load.
     */
    public function picker(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Document::class);

        $documents = Document::query()
            ->whereIn('category', array_map(
                static fn (DocumentCategory $c): string => $c->value,
                self::UPLOADABLE,
            ))
            ->where('is_current', true)
            ->latest('id')
            ->limit(60)
            ->get();

        return response()->json([
            'images' => $documents->map(static fn (Document $d): array => [
                'id' => $d->getKey(),
                'name' => $d->original_name,
                'url' => $d->url(),
                'width' => $d->image_width,
                'height' => $d->image_height,
            ])->all(),
        ]);
    }
}
