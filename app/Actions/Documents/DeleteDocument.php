<?php

namespace App\Actions\Documents;

use App\Models\Document;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Documents\DocumentStorage;
use Illuminate\Support\Facades\DB;

class DeleteDocument
{
    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly AuditLogger $auditLogger,
    ) {}

    /**
     * Soft-deletes the record and purges the file.
     *
     * The row is kept so the audit trail still shows what existed and who
     * removed it; the bytes are destroyed because retaining an identity
     * document after deletion is exactly what the customer asked us not to do.
     */
    public function execute(User $actor, Document $document, ?string $reason = null): void
    {
        $file = DB::transaction(function () use ($actor, $document, $reason): ?array {
            $lockedActor = User::query()->whereKey($actor->getKey())->lockForUpdate()->firstOrFail();

            $locked = Document::query()
                ->whereKey($document->getKey())
                ->lockForUpdate()
                ->first();

            if ($locked === null) {
                return null;
            }

            $file = ['disk' => $locked->disk, 'path' => $locked->path];

            $locked->forceFill(['is_current' => false])->save();
            $locked->delete();

            $this->auditLogger->record(
                event: 'document.deleted',
                auditable: $locked,
                oldValues: [
                    'documentable_type' => $locked->documentable_type,
                    'documentable_id' => $locked->documentable_id,
                    'category' => $locked->category->value,
                    'version' => (int) $locked->version,
                ],
                newValues: ['deleted_at' => now()->toIso8601String()],
                context: ['reason_present' => $reason !== null],
                user: $lockedActor,
            );

            return $file;
        }, 3);

        if ($file !== null) {
            $this->storage->delete($file['disk'], $file['path']);
        }
    }
}
