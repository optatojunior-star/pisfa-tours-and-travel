<?php

namespace App\Enums;

enum SelfDriveApplicationStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case NeedsInformation = 'needs_information';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Submitted => 'Pending verification',
            self::NeedsInformation => 'More information required',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    public function isCustomerEditable(): bool
    {
        return in_array($this, [self::Draft, self::NeedsInformation], true);
    }
}
