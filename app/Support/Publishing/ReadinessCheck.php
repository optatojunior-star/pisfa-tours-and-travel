<?php

namespace App\Support\Publishing;

/**
 * One thing that must be true before a record may go on the public site.
 *
 * A check knows three things a bare validation message does not: what is wrong
 * in plain words, what to do about it, and where on the screen to go and do it.
 * That last part is the difference between "A published vehicle needs a
 * currently effective supported rate for at least one hire mode" and a link
 * that scrolls you to the rate form with the field already focused.
 */
final class ReadinessCheck
{
    /**
     * @param  string  $field  the form field the failure belongs against, so the
     *                         message lands beside the control that fixes it
     * @param  string|null  $anchor  element id on the record's own screen
     * @param  string|null  $actionLabel  text for the link to that anchor
     */
    public function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly bool $passed,
        public readonly string $field,
        public readonly string $problem = '',
        public readonly string $fix = '',
        public readonly ?string $anchor = null,
        public readonly ?string $actionLabel = null,
    ) {}

    public static function pass(string $key, string $label, string $field, string $detail = ''): self
    {
        return new self($key, $label, true, $field, $detail);
    }

    public static function fail(
        string $key,
        string $label,
        string $field,
        string $problem,
        string $fix,
        ?string $anchor = null,
        ?string $actionLabel = null,
    ): self {
        return new self($key, $label, false, $field, $problem, $fix, $anchor, $actionLabel);
    }

    /** The sentence shown as a validation error: what is wrong, then what to do. */
    public function message(): string
    {
        return trim($this->problem.' '.$this->fix);
    }
}
