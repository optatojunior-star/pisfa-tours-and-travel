<?php

namespace App\Enums;

enum CarHireDocumentType: string
{
    case NationalId = 'national_id';
    case DrivingPermit = 'driving_permit';
    case ApplicantPhoto = 'applicant_photo';

    public function label(): string
    {
        return match ($this) {
            self::NationalId => 'National ID',
            self::DrivingPermit => 'Driving permit',
            self::ApplicantPhoto => 'Applicant photograph',
        };
    }
}
