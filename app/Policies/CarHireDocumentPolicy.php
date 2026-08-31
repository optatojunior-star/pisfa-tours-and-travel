<?php

namespace App\Policies;

use App\Models\CarHireDocument;
use App\Models\User;

class CarHireDocumentPolicy
{
    public function view(User $user, CarHireDocument $document): bool
    {
        return $user->can('downloadDocument', $document->booking);
    }

    public function delete(User $user, CarHireDocument $document): bool
    {
        return $user->can('uploadDocument', $document->booking);
    }
}
