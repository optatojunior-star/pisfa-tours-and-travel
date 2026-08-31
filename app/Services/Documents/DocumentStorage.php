<?php

namespace App\Services\Documents;

use App\Enums\DocumentVisibility;
use App\Models\Document;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The single place files enter and leave storage.
 *
 * Laravel's filesystem already abstracts local, S3-compatible, and (via a
 * driver) Cloudinary, so this is a thin, deliberate seam rather than a second
 * abstraction: it fixes the disk from the document's visibility, forces private
 * writes to be private, and guarantees a generated filename. Swapping
 * PISFA_PRIVATE_DISK to s3 changes nothing above this class.
 */
class DocumentStorage
{
    public function __construct(private readonly FileInspector $inspector) {}

    /**
     * @return array{disk: string, path: string}
     */
    public function putUpload(
        UploadedFile $file,
        DocumentVisibility $visibility,
        string $directory,
        string $extension,
    ): array {
        $disk = $visibility->disk();
        $directory = $this->normalizeDirectory($directory);
        $filename = $this->inspector->safeFilename($extension);

        $stored = Storage::disk($disk)->putFileAs(
            $directory,
            $file,
            $filename,
            ['visibility' => $visibility->value],
        );

        if ($stored === false) {
            throw new RuntimeException('The file could not be written to storage.');
        }

        return ['disk' => $disk, 'path' => $directory.'/'.$filename];
    }

    /**
     * @return array{disk: string, path: string}
     */
    public function putContents(
        string $contents,
        DocumentVisibility $visibility,
        string $directory,
        string $extension,
    ): array {
        $disk = $visibility->disk();
        $directory = $this->normalizeDirectory($directory);
        $path = $directory.'/'.$this->inspector->safeFilename($extension);

        if (! Storage::disk($disk)->put($path, $contents, ['visibility' => $visibility->value])) {
            throw new RuntimeException('The generated document could not be written to storage.');
        }

        return ['disk' => $disk, 'path' => $path];
    }

    public function delete(string $disk, string $path): void
    {
        Storage::disk($disk)->delete($path);
    }

    public function deleteDocumentFile(Document $document): void
    {
        $this->delete($document->disk, $document->path);
    }

    /**
     * Streams rather than reading into memory, so a large PDF cannot exhaust
     * the modest memory limit on shared hosting.
     */
    public function download(Document $document, bool $inline = false): StreamedResponse
    {
        $disk = Storage::disk($document->disk);

        if (! $disk->exists($document->path)) {
            throw new RuntimeException('The stored file is missing.');
        }

        return $disk->download(
            $document->path,
            $document->downloadName(),
            [
                'Content-Type' => $document->mime_type,
                'Content-Disposition' => ($inline ? 'inline' : 'attachment')
                    .'; filename="'.$document->downloadName().'"',
                // A private document must never be cached by a proxy.
                'Cache-Control' => 'private, no-store, max-age=0',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }

    /**
     * Path segments are built from application values, never request input, but
     * this normalisation makes traversal impossible even if that changes.
     */
    private function normalizeDirectory(string $directory): string
    {
        $segments = array_filter(
            explode('/', str_replace('\\', '/', $directory)),
            static fn (string $segment): bool => $segment !== ''
                && $segment !== '.'
                && $segment !== '..',
        );

        $clean = array_map(
            static fn (string $segment): string => preg_replace('/[^A-Za-z0-9_-]/', '-', $segment) ?? 'segment',
            $segments,
        );

        return implode('/', $clean) ?: 'misc';
    }
}
