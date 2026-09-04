<?php

namespace App\Http\Controllers\Concerns;

use App\Actions\Documents\StoreDocument;
use App\Contracts\HasPhotographs;
use App\Enums\DocumentCategory;
use App\Models\Document;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Attaches uploaded photographs to whatever record is being saved.
 *
 * Every admin form used to have no file input at all, which is why the site was
 * full of "Photographs coming soon" — the storage layer existed, the inspector
 * existed, the actions existed, and there was simply no way for a person to put
 * a picture into any of it.
 *
 * Validation of the file itself is not repeated here. StoreDocument runs
 * FileInspector, which reads the real content rather than trusting the
 * extension: magic bytes, embedded script, dimensions, size. The rules below
 * only reject what is obviously wrong before a large body is read into memory.
 */
trait HandlesImageUploads
{
    /**
     * Stores whatever images the request carried.
     *
     * Returns the messages for any that were rejected. One bad file does not
     * discard the batch: somebody attaching twelve photographs of a lodge
     * should not lose eleven because the twelfth was a screenshot renamed .jpg.
     *
     * @return list<string>
     */
    protected function storeUploadedImages(
        Request $request,
        Model $owner,
        DocumentCategory $category,
        string $field = 'images',
    ): array {
        if (! $request->hasFile($field)) {
            return [];
        }

        $files = $request->file($field);
        $files = is_array($files) ? $files : [$files];

        /** @var User $actor */
        $actor = $request->user();
        $action = app(StoreDocument::class);
        $rejected = [];

        // Existing images decide where new ones sort, so an upload appends
        // rather than silently becoming the cover photograph.
        $nextSort = (int) Document::query()
            ->where('documentable_type', $owner->getMorphClass())
            ->where('documentable_id', $owner->getKey())
            ->where('category', $category->value)
            ->max('sort_order');

        foreach ($files as $file) {
            try {
                $action->execute(
                    actor: $actor,
                    owner: $owner,
                    category: $category,
                    file: $file,
                    sortOrder: ++$nextSort,
                );
            } catch (ValidationException $exception) {
                $rejected[] = $file->getClientOriginalName().' — '
                    .(collect($exception->errors())->flatten()->first() ?? 'could not be accepted.');
            }
        }

        return $rejected;
    }

    /**
     * Uploads photographs and mirrors them into the record's own media table.
     *
     * Tours, vehicles and properties all keep a small public media row beside
     * the stored Document — the URL, the caption, and which picture is the
     * cover. Writing that bridge once rather than in each controller is not
     * only shorter: the three copies had already drifted, and a rule fixed in
     * one was still wrong in the others.
     *
     * @return list<string> messages for any file that was refused
     */
    protected function copyUploadsToMedia(
        Request $request,
        HasPhotographs&Model $owner,
        DocumentCategory $category,
        string $altText,
        string $field = 'images',
    ): array {
        if (! $request->hasFile($field)) {
            return [];
        }

        // An id boundary rather than an offset. skip() without limit() emits
        // OFFSET with no LIMIT, which SQLite rejects outright — and reading the
        // id before the upload is what tells the new rows from the old.
        $lastId = (int) $owner->photographs()->max('id');

        $rejected = $this->storeUploadedImages($request, $owner, $category, $field);

        $sort = (int) $owner->media()->max('sort_order');
        $hasCover = $owner->media()->where('is_cover', true)->exists();

        foreach ($owner->photographs()->where('id', '>', $lastId)->get() as $document) {
            $owner->media()->create([
                'url' => $document->url(),
                'alt_text' => $altText,
                // The first picture on a record with no cover becomes the
                // cover, so a catalogue card is never blank by default.
                'is_cover' => ! $hasCover,
                'sort_order' => ++$sort,
            ]);

            $hasCover = true;
        }

        return $rejected;
    }

    /**
     * The validation rules for an image field.
     *
     * @return array<string, mixed>
     */
    protected function imageRules(string $field = 'images', int $max = 12): array
    {
        $maxKb = (int) config('documents.images.maximum_kilobytes', 5120);

        return [
            $field => ['nullable', 'array', 'max:'.$max],
            $field.'.*' => ['file', 'mimes:jpg,jpeg,png,webp', 'max:'.$maxKb],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function imageMessages(string $field = 'images'): array
    {
        $maxKb = (int) config('documents.images.maximum_kilobytes', 5120);

        return [
            $field.'.*.mimes' => 'Photographs must be JPG, PNG or WebP.',
            $field.'.*.max' => 'Each photograph must be under '.round($maxKb / 1024, 1).' MB.',
        ];
    }

    /**
     * Flashes a partial-success message when some files were refused.
     *
     * @param  list<string>  $rejected
     */
    protected function withRejectedImages(mixed $redirect, array $rejected): mixed
    {
        return $rejected === [] ? $redirect : $redirect->with('rejected', $rejected);
    }
}
