<?php

namespace App\Enums;

enum ReviewModerationAction: string
{
    case Submitted = 'submitted';
    case Edited = 'edited';
    case Approved = 'approved';
    case Unpublished = 'unpublished';
    case Rejected = 'rejected';
    case Replied = 'replied';
    case Deleted = 'deleted';

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Submitted',
            self::Edited => 'Edited by the author',
            self::Approved => 'Approved and published',
            self::Unpublished => 'Unpublished',
            self::Rejected => 'Rejected',
            self::Replied => 'PISFA replied',
            self::Deleted => 'Deleted',
        };
    }

    /** Actions taken by a moderator rather than by the author. */
    public function isModeratorAction(): bool
    {
        return in_array($this, [
            self::Approved,
            self::Unpublished,
            self::Rejected,
            self::Replied,
        ], true);
    }
}
