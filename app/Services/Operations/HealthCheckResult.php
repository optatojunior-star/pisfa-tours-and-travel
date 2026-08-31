<?php

namespace App\Services\Operations;

/**
 * What one check found.
 *
 * The `detail` is written for whoever is on call at the time: it says what is
 * wrong and what to do about it, and it never carries a credential, a
 * connection string, or a host name. Health output is the one place where an
 * unauthenticated reader is most likely to be looking.
 */
final class HealthCheckResult
{
    private function __construct(
        public readonly string $name,
        public readonly HealthStatus $status,
        public readonly string $detail,
        public readonly ?float $milliseconds = null,
    ) {}

    public static function passing(string $name, string $detail = 'OK', ?float $milliseconds = null): self
    {
        return new self($name, HealthStatus::Passing, $detail, $milliseconds);
    }

    public static function warning(string $name, string $detail, ?float $milliseconds = null): self
    {
        return new self($name, HealthStatus::Warning, $detail, $milliseconds);
    }

    public static function failing(string $name, string $detail, ?float $milliseconds = null): self
    {
        return new self($name, HealthStatus::Failing, $detail, $milliseconds);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'name' => $this->name,
            'status' => $this->status->value,
            'detail' => $this->detail,
            'ms' => $this->milliseconds === null ? null : round($this->milliseconds, 1),
        ], static fn (mixed $value): bool => $value !== null);
    }
}
