<?php

namespace App\Actions;

use App\Models\Membership;
use App\Models\User;
use InvalidArgumentException;

/**
 * Attach an existing person as a managed dependant.
 *
 * A guardian who is not an active paid member (free registrant, lapsed,
 * or no membership at all) gets a free non-member standing for a
 * dependant who does not already have a membership. An active paid
 * guardian is unchanged: their dependant joins through the paid family
 * membership flow.
 */
class LinkFreeDependant
{
    public function link(User $guardian, User $dependant, string $relationship = 'junior'): void
    {
        if (! array_key_exists($relationship, User::MANAGED_RELATIONSHIPS)) {
            throw new InvalidArgumentException('Choose a family relationship.');
        }

        if ($guardian->id === $dependant->id) {
            throw new InvalidArgumentException('An account cannot be its own dependant.');
        }

        if ($guardian->isManaged()) {
            throw new InvalidArgumentException('Link the dependant to the account that logs in, not to another managed account.');
        }

        $cursor = $guardian->parent;
        while ($cursor) {
            if ($cursor->id === $dependant->id) {
                throw new InvalidArgumentException('That link would put the family in a loop.');
            }
            $cursor = $cursor->parent;
        }

        $dependant->parent_id = $guardian->id;
        $dependant->is_managed_account = true;
        $dependant->managed_relationship = $relationship;
        $dependant->save();

        $this->ensureFreeStanding($guardian, $dependant);
    }

    public function ensureFreeStanding(User $guardian, User $dependant): void
    {
        if ($this->guardianIsPayingMember($guardian)) {
            return;
        }

        $dependant->loadMissing('membership');
        if ($dependant->membership) {
            return;
        }

        Membership::create([
            'user_id' => $dependant->id,
            'membership_type' => 'free',
            'status' => 'active',
            'payment_status' => 'unpaid',
        ]);
    }

    public function guardianIsPayingMember(User $guardian): bool
    {
        $guardian->loadMissing('membership');

        return (bool) $guardian->membership?->isActiveMember();
    }
}
