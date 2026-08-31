<?php

namespace App\Services;

use App\Models\StaffInvitation;

final readonly class IssuedStaffInvitation
{
    public function __construct(
        public StaffInvitation $invitation,
        private string $rawToken,
    ) {}

    public function notification(): StaffInvitationNotification
    {
        return new StaffInvitationNotification($this->invitation, $this->rawToken);
    }
}
