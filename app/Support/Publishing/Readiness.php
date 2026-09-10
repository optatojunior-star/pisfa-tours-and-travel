<?php

namespace App\Support\Publishing;

use Illuminate\Validation\ValidationException;

/**
 * The result of asking "can this go live yet?".
 *
 * Holds every check, passed and failed, because a checklist that only shows
 * problems cannot tell you how close you are. The screen renders all of them;
 * the validator raises only the failures, each against its own field.
 */
final class Readiness
{
    /** @param list<ReadinessCheck> $checks */
    public function __construct(public readonly array $checks) {}

    public function isReady(): bool
    {
        return $this->failures() === [];
    }

    /** @return list<ReadinessCheck> */
    public function failures(): array
    {
        return array_values(array_filter($this->checks, static fn (ReadinessCheck $c): bool => ! $c->passed));
    }

    public function passedCount(): int
    {
        return count($this->checks) - count($this->failures());
    }

    public function total(): int
    {
        return count($this->checks);
    }

    /**
     * Raises every outstanding failure at once, each against the field that
     * fixes it.
     *
     * All of them, not the first. Being told to add a photograph, adding one,
     * and only then being told a rate is also missing is two round trips for
     * one problem — and it is what makes a publish button feel like it is
     * hiding the rules from you.
     *
     * @param  string  $fallbackField  used when a failure has no field of its own
     */
    public function raise(string $fallbackField = 'catalogue_status'): never
    {
        $messages = [];

        foreach ($this->failures() as $failure) {
            $field = $failure->field !== '' ? $failure->field : $fallbackField;
            $messages[$field][] = $failure->message();
        }

        throw ValidationException::withMessages($messages);
    }
}
