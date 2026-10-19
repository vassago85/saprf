<?php

namespace App\Policies;

use App\Models\Payout;
use App\Models\User;

class PayoutPolicy
{
    /**
     * MD sees their own payouts; finance roles see them all. No cross-MD.
     */
    public function view(User $user, Payout $payout): bool
    {
        if ($user->hasAnyRole(['developer', 'exco', 'owner', 'admin'])) {
            return true;
        }

        return $payout->payee_user_id === $user->id;
    }

    /**
     * Only finance roles can mutate payout state (mark paid, regenerate).
     */
    public function manage(User $user, Payout $payout): bool
    {
        return $user->hasAnyRole(['developer', 'exco', 'owner', 'admin']);
    }
}
