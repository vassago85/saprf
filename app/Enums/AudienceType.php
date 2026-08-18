<?php

namespace App\Enums;

/**
 * Audience rule primitives the composer can layer together. Each rule
 * stores its type + a JSON `value` payload; `AudienceMode` decides
 * whether the resolved ids are added to the include set or subtracted.
 *
 * Notes on SAPRF terminology (deliberate; the spec used older terms):
 *   - `division` matches `users.division_id` (Open, Factory, Limited,
 *     Production, Ladies, Junior, Senior). There is no separate
 *     "category" dimension on users any more.
 *   - `series` is a MATCH attribute (`PRS` / `PR22`), not a user
 *     attribute — the resolver expands it into "users who registered
 *     for or scored in that series this season".
 *   - `region` in the spec is our `province_id`.
 */
enum AudienceType: string
{
    case All = 'all';
    case ActiveMembers = 'active_members';
    case MembershipType = 'membership_type';
    case FeeTier = 'fee_tier';
    case Division = 'division';
    case Series = 'series';
    case Role = 'role';
    case Club = 'club';
    case Province = 'province';
    case Individual = 'individual';
    case SavedList = 'saved_list';

    public function label(): string
    {
        return match ($this) {
            self::All => 'Everyone',
            self::ActiveMembers => 'Active members',
            self::MembershipType => 'Membership type',
            self::FeeTier => 'Fee tier',
            self::Division => 'Division',
            self::Series => 'Series',
            self::Role => 'Role',
            self::Club => 'Club',
            self::Province => 'Province',
            self::Individual => 'Named individuals',
            self::SavedList => 'Saved list',
        };
    }
}
