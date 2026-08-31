<?php

namespace App\Services\Operations;

/**
 * How healthy one check is.
 *
 * `Warning` exists because most operational problems are not binary. Disk at
 * 85%, a queue with a growing backlog, and a certificate expiring next week are
 * all things somebody should look at today and none of them should take the
 * site down or page anybody at 3am.
 */
enum HealthStatus: string
{
    case Passing = 'passing';
    case Warning = 'warning';
    case Failing = 'failing';

    public function label(): string
    {
        return match ($this) {
            self::Passing => 'Healthy',
            self::Warning => 'Needs attention',
            self::Failing => 'Failing',
        };
    }

    /**
     * Whether this state should make the site report itself as down.
     *
     * Only a failure does. A warning that returned 503 would have a monitoring
     * system restart a perfectly serving application because a log directory
     * was getting full.
     */
    public function isDown(): bool
    {
        return $this === self::Failing;
    }

    public function severity(): int
    {
        return match ($this) {
            self::Passing => 0,
            self::Warning => 1,
            self::Failing => 2,
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Passing => 'emerald',
            self::Warning => 'amber',
            self::Failing => 'rose',
        };
    }

    /** The worst of a set, which is the state of the system as a whole. */
    public static function worst(self ...$statuses): self
    {
        $worst = self::Passing;

        foreach ($statuses as $status) {
            if ($status->severity() > $worst->severity()) {
                $worst = $status;
            }
        }

        return $worst;
    }
}
