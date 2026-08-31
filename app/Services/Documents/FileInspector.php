<?php

namespace App\Services\Documents;

use App\Enums\DocumentCategory;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Inspects an upload against its real content before anything is stored.
 *
 * Nothing here trusts the client. The browser-supplied Content-Type and the
 * original filename are both attacker-controlled, so the MIME type is read from
 * the file's own bytes with finfo and the extension is only ever used to detect
 * a *mismatch* with what was detected.
 */
class FileInspector
{
    /**
     * Byte signatures for every accepted type. finfo is authoritative, but a
     * magic-byte check costs nothing and catches a misconfigured or patched
     * finfo database.
     *
     * @var array<string, list<string>>
     */
    private const SIGNATURES = [
        'image/jpeg' => ["\xFF\xD8\xFF"],
        'image/png' => ["\x89PNG\r\n\x1A\n"],
        'image/webp' => ['RIFF'],
        'application/pdf' => ['%PDF-'],
    ];

    /**
     * Content that must never be stored even if it somehow sniffs as an
     * accepted type. A polyglot file that is both a valid image and a valid
     * script is the classic upload bypass.
     */
    private const FORBIDDEN_MARKERS = [
        '<?php',
        '<?=',
        '<script',
        '#!/',
        '<%',
    ];

    /**
     * @return array{mime: string, extension: string, size: int, checksum: string, width: ?int, height: ?int}
     */
    public function inspect(UploadedFile $file, DocumentCategory $category): array
    {
        if (! $file->isValid()) {
            $this->reject('The upload did not complete. Try again.');
        }

        $size = (int) $file->getSize();

        if ($size < 1) {
            $this->reject('The file is empty.');
        }

        $path = $file->getRealPath();

        if ($path === false || ! is_readable($path)) {
            $this->reject('The uploaded file could not be read.');
        }

        $mime = $this->detectMime($path);
        $accepted = (array) config('documents.accepted', []);

        if (! array_key_exists($mime, $accepted)) {
            $this->reject('Upload a JPEG, PNG, WebP, or PDF file.');
        }

        $this->assertSignatureMatches($path, $mime);
        $this->assertNoEmbeddedCode($path, $mime);
        $this->assertExtensionMatches($file, $mime, (array) $accepted[$mime]);

        $isImage = in_array($mime, (array) config('documents.images.mimes', []), true);

        if ($category->requiresImage() && ! $isImage) {
            $this->reject($category->label().' must be an image file.');
        }

        $this->assertSizeWithinLimit($size, $isImage);

        [$width, $height] = $isImage ? $this->assertImageDimensions($path) : [null, null];

        $checksum = hash_file('sha256', $path);

        if (! is_string($checksum)) {
            $this->reject('The uploaded file could not be fingerprinted.');
        }

        return [
            'mime' => $mime,
            'extension' => (string) $accepted[$mime][0],
            'size' => $size,
            'checksum' => $checksum,
            'width' => $width,
            'height' => $height,
        ];
    }

    /**
     * A storage filename never derives from user input. Directory traversal,
     * null bytes, double extensions, and unicode homographs all stop being a
     * concern when the name is generated.
     */
    public function safeFilename(string $extension): string
    {
        return bin2hex(random_bytes(16)).'.'.$extension;
    }

    private function detectMime(string $path): string
    {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        if ($finfo === false) {
            $this->reject('The server cannot inspect uploads right now.');
        }

        // Warnings are suppressed and converted into a rejection on purpose. A
        // transient I/O failure must refuse the upload, never escape as a 500
        // and never fall through to treating the file as acceptable.
        $mime = @finfo_file($finfo, $path);
        finfo_close($finfo);

        if (! is_string($mime) || $mime === '') {
            $this->reject('The file could not be inspected. Please try uploading it again.');
        }

        return mb_strtolower($mime);
    }

    private function assertSignatureMatches(string $path, string $mime): void
    {
        $signatures = self::SIGNATURES[$mime] ?? [];

        if ($signatures === []) {
            return;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            $this->reject('The file could not be read. Please try uploading it again.');
        }

        $header = (string) fread($handle, 16);
        fclose($handle);

        foreach ($signatures as $signature) {
            if (str_starts_with($header, $signature)) {
                return;
            }
        }

        $this->reject('The file content does not match a supported format.');
    }

    /**
     * Scans the head of the file for executable markers. An image with PHP in
     * its comment block is harmless on a correctly configured server, but the
     * private disk is not the place to find out.
     */
    private function assertNoEmbeddedCode(string $path, string $mime): void
    {
        if ($mime === 'application/pdf') {
            // A PDF legitimately contains binary streams; the extension and
            // storage location already prevent it from ever being executed.
            return;
        }

        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            $this->reject('The file could not be read. Please try uploading it again.');
        }

        $head = mb_strtolower((string) fread($handle, 8192));
        fclose($handle);

        foreach (self::FORBIDDEN_MARKERS as $marker) {
            if (str_contains($head, $marker)) {
                $this->reject('The file contains embedded code and was rejected.');
            }
        }
    }

    /**
     * @param  list<string>  $allowedExtensions
     */
    private function assertExtensionMatches(
        UploadedFile $file,
        string $mime,
        array $allowedExtensions,
    ): void {
        $extension = mb_strtolower(trim((string) $file->getClientOriginalExtension()));

        if ($extension === '' || ! in_array($extension, $allowedExtensions, true)) {
            $this->reject('The file extension does not match its detected content.');
        }
    }

    private function assertSizeWithinLimit(int $size, bool $isImage): void
    {
        $limitKb = $isImage
            ? (int) config('documents.images.maximum_kilobytes', 5120)
            : (int) config('documents.files.maximum_kilobytes', 10240);

        if ($size > $limitKb * 1024) {
            $megabytes = round($limitKb / 1024, 1);
            $this->reject("The file is larger than the {$megabytes} MB limit.");
        }
    }

    /** @return array{0: int, 1: int} */
    private function assertImageDimensions(string $path): array
    {
        $dimensions = @getimagesize($path);

        if ($dimensions === false) {
            $this->reject('The image could not be read.');
        }

        [$width, $height] = $dimensions;
        $config = (array) config('documents.images', []);

        if ($width < (int) ($config['minimum_width'] ?? 0)
            || $height < (int) ($config['minimum_height'] ?? 0)) {
            $this->reject(sprintf(
                'The image must be at least %dx%d pixels.',
                (int) ($config['minimum_width'] ?? 0),
                (int) ($config['minimum_height'] ?? 0),
            ));
        }

        // An enormous declared canvas is a decompression-bomb vector: the
        // pixel buffer is allocated before anything else can reject it.
        if ($width > (int) ($config['maximum_width'] ?? PHP_INT_MAX)
            || $height > (int) ($config['maximum_height'] ?? PHP_INT_MAX)) {
            $this->reject('The image dimensions are too large.');
        }

        return [(int) $width, (int) $height];
    }

    /** @return never */
    private function reject(string $message): void
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
