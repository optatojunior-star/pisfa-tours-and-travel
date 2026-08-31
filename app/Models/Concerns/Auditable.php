<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use App\Services\AuditLogger;
use Illuminate\Contracts\Auth\Authenticatable;

trait Auditable
{
    public function recordAudit(
        string $event,
        array $oldValues = [],
        array $newValues = [],
        array $context = [],
        ?Authenticatable $user = null,
    ): AuditLog {
        return app(AuditLogger::class)->record(
            event: $event,
            auditable: $this,
            oldValues: $oldValues,
            newValues: $newValues,
            context: $context,
            user: $user,
        );
    }
}
