<?php

namespace App\Policies;

use App\Models\Rental;
use App\Models\User;

class RentalPolicy
{
    public function view(User $user, Rental $rental): bool
    {
        return $user->tenant_id === $rental->tenant_id
            && ($user->id === $rental->user_id || $user->hasAnyRole(Rental::STAFF_ROLES));
    }
}
