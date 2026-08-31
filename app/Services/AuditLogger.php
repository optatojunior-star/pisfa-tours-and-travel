<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use InvalidArgumentException;

class AuditLogger
{
    public function __construct(
        private readonly AuthFactory $auth,
        private readonly Request $request,
    ) {}

    public function record(
        string $event,
        ?Model $auditable = null,
        array $oldValues = [],
        array $newValues = [],
        array $context = [],
        ?Authenticatable $user = null,
    ): AuditLog {
        if (trim($event) === '') {
            throw new InvalidArgumentException('An audit event cannot be empty.');
        }

        $user ??= $this->auth->guard()->user();

        $auditLog = new AuditLog([
            'user_id' => $user?->getAuthIdentifier(),
            'event' => $event,
            'old_values' => $this->redactSensitiveValues($oldValues),
            'new_values' => $this->redactSensitiveValues($newValues),
            'context' => $this->redactSensitiveValues(
                Arr::except($context, ['url', 'ip_address', 'user_agent'])
            ),
            'url' => $this->redactSensitiveUrl(
                $context['url'] ?? $this->request->fullUrl(),
            ),
            'ip_address' => $context['ip_address'] ?? $this->request->ip(),
            'user_agent' => $context['user_agent'] ?? $this->request->userAgent(),
        ]);

        if ($auditable !== null) {
            $auditLog->auditable()->associate($auditable);
        }

        $auditLog->save();

        return $auditLog;
    }

    private function redactSensitiveValues(array $values): array
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && preg_match(
                '/(?:password|token|secret|authorization|cookie|signature|api[_-]?key|private[_-]?key|recovery[_-]?codes?|one[_-]?time[_-]?(?:password|code)|two[_-]?factor[_-]?code|otp|national[_-]?id|identity|passport|driving[_-]?(?:permit|licen[cs]e)|permit[_-]?(?:number|path)|licen[cs]e[_-]?(?:number|path)|document[_-]?(?:path|url)|idempotency[_-]?(?:key|owner[_-]?hash)|request[_-]?fingerprint|contact[_-]?(?:name|email|phone)|service[_-]?address|flight[_-]?number|special[_-]?requests|internal[_-]?notes|(?:cancellation|unassignment|replacement)[_-]?reason)/i',
                $key,
            )) {
                $values[$key] = '[REDACTED]';

                continue;
            }

            if (is_array($value)) {
                $values[$key] = $this->redactSensitiveValues($value);
            }
        }

        return $values;
    }

    private function redactSensitiveUrl(?string $url): ?string
    {
        if ($url === null || $url === '') {
            return $url;
        }

        $redacted = preg_replace(
            '#(?i)(/(?:staff/invitations|reset-password)/)[^/?\#]+#',
            '$1[REDACTED]',
            $url,
        ) ?? $url;

        $redacted = preg_replace(
            '#(?i)(/verify-email/)[^/?\#]+/[^/?\#]+#',
            '$1[REDACTED]/[REDACTED]',
            $redacted,
        ) ?? $redacted;

        return preg_replace(
            '/([?&](?:authorization|code|key|password|secret|signature|token)=)[^&#]*/i',
            '$1[REDACTED]',
            $redacted,
        ) ?? $redacted;
    }
}
