<?php

namespace App\Enums;

enum DocumentVisibility: string
{
    case Private = 'private';
    case Public = 'public';

    public function label(): string
    {
        return match ($this) {
            self::Private => 'Private',
            self::Public => 'Public',
        };
    }

    /**
     * The configured disk this visibility writes to.
     */
    public function disk(): string
    {
        return match ($this) {
            self::Private => (string) config('documents.disks.private', 'local'),
            self::Public => (string) config('documents.disks.public', 'public'),
        };
    }

    /**
     * A private file is never given a direct URL. It is streamed by an
     * authorized controller or reached through a short-lived signed route.
     */
    public function allowsDirectUrl(): bool
    {
        return $this === self::Public;
    }
}
