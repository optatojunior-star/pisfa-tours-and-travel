<?php

namespace App\Services\Documents;

use App\Enums\DocumentCategory;
use App\Models\Document;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Settings\SettingsRepository;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Renders branded PDFs and files them as versioned documents.
 *
 * DomPDF is used because Hostinger Premium is shared hosting: it is pure PHP
 * and needs no Chrome binary or system package, unlike Browsershot or
 * wkhtmltopdf. The trade-off is limited CSS support, so the PDF layouts use
 * tables and inline styles rather than flexbox or grid.
 */
class PdfRenderer
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly AuditLogger $auditLogger,
        private readonly SettingsRepository $settings,
    ) {}

    /**
     * Render a Blade view to PDF bytes without storing it. Useful for a
     * preview screen where nothing should be filed yet.
     *
     * @param  array<string, mixed>  $data
     */
    public function render(string $view, array $data = []): string
    {
        return Pdf::loadView($view, $data + ['brand' => $this->brand()])
            ->setPaper(
                (string) config('documents.pdf.paper', 'a4'),
                (string) config('documents.pdf.orientation', 'portrait'),
            )
            ->output();
    }

    /**
     * Render and file a new version against the owning record.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $metadata
     */
    public function store(
        ?User $actor,
        Model $owner,
        DocumentCategory $category,
        string $view,
        array $data = [],
        array $metadata = [],
    ): Document {
        $contents = $this->render($view, $data);
        $visibility = $category->visibility();

        $written = $this->storage->putContents(
            $contents,
            $visibility,
            str(class_basename($owner))->snake()->plural().'/'.$owner->getKey().'/'.$category->value,
            'pdf',
        );

        try {
            return DB::transaction(function () use (
                $actor,
                $owner,
                $category,
                $metadata,
                $visibility,
                $written,
                $contents,
            ): Document {
                $previous = Document::query()
                    ->where('documentable_type', $owner->getMorphClass())
                    ->where('documentable_id', $owner->getKey())
                    ->ofCategory($category)
                    ->current()
                    ->lockForUpdate()
                    ->get();

                $highestVersion = (int) Document::query()
                    ->where('documentable_type', $owner->getMorphClass())
                    ->where('documentable_id', $owner->getKey())
                    ->ofCategory($category)
                    ->withTrashed()
                    ->max('version');

                // A superseded contract or invoice is retained, never purged:
                // it may already have been sent, signed, or paid against.
                $previous->each(fn (Document $document) => $document->forceFill(['is_current' => false])->save());

                $document = new Document;
                $document->forceFill([
                    'documentable_type' => $owner->getMorphClass(),
                    'documentable_id' => $owner->getKey(),
                    'category' => $category,
                    'visibility' => $visibility,
                    'disk' => $written['disk'],
                    'path' => $written['path'],
                    'original_name' => null,
                    'mime_type' => 'application/pdf',
                    'size_bytes' => strlen($contents),
                    'content_sha256' => hash('sha256', $contents),
                    'version' => $highestVersion + 1,
                    'is_current' => true,
                    'uploaded_by_user_id' => $actor?->getKey(),
                    'is_generated' => true,
                    'metadata' => $metadata === [] ? null : $metadata,
                ])->save();

                $this->auditLogger->record(
                    event: 'document.generated',
                    auditable: $document,
                    newValues: [
                        'documentable_type' => $document->documentable_type,
                        'documentable_id' => $document->documentable_id,
                        'category' => $category->value,
                        'version' => $document->version,
                        'size_bytes' => $document->size_bytes,
                        'superseded' => $previous->count(),
                    ],
                    user: $actor,
                );

                return $document;
            }, 3);
        } catch (Throwable $exception) {
            $this->storage->delete($written['disk'], $written['path']);

            throw $exception;
        }
    }

    /**
     * Branding pulled from settings so a rename or address change does not
     * require a redeploy. A stored value wins; config is the shipped default.
     *
     * @return array<string, string>
     */
    private function brand(): array
    {
        return $this->settings->brand();
    }
}
