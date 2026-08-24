<?php

namespace App\Data;

use App\Models\StaffInvitation;

class StaffInvitationResult
{
    public function __construct(
        public StaffInvitation $invitation,
        public string $plainTextToken,
    ) {}
}
