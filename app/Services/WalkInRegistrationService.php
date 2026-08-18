<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turn a match director's walk-in confirmation into a durable
 * `MatchRegistration` row.
 *
 * Context
 * -------
 * A "walk-in" is a shooter who shot the match without registering through
 * the site. The range collected their entry fee directly (cash / EFT), so
 * no PayFast transaction exists. For their score to count on the standings
 * we still need a `MatchRegistration` — that's the source of truth for
 * division, fee owed, and audit trail.
 *
 * What this service records:
 *   - fee_amount         = 0         (site collected nothing)
 *   - saprf_fee          = normal    (owed by the range on this shooter)
 *   - platform_fee       = normal    (owed by the range on this shooter)
 *   - gateway_fee        = 0         (no PayFast, no card cost)
 *   - md_net_amount      = -(saprf + platform)   (deducted from range payout)
 *   - registration_source = 'walk_in'
 *   - walk_in_note       = MD's free-text reason (visible to exco)
 *   - walk_in_confirmed_by / at = who confirmed and when
 *
 * User resolution:
 *   1. If `email` is supplied, find-or-create a user with that email.
 *   2. Otherwise, look for an existing user by canonical name match.
 *   3. If still nothing, create a "shadow user" with a synthesized email
 *      pointing at the reserved `saprf.walkin` domain. Shadow users have
 *      an unusable password and can be merged into a real account later
 *      by an admin.
 */
class WalkInRegistrationService
{
    public function __construct(
        private readonly RegistrationPricingService $pricing,
    ) {}

    /**
     * Confirm a walk-in and create the backing MatchRegistration row.
     *
     * @param  array{name:string,email:?string,division_slug:string,membership_status:?string,note:?string}  $data
     */
    public function confirmWalkIn(MatchEvent $match, array $data, ?User $confirmedBy = null): MatchRegistration
    {
        $name = trim($data['name'] ?? '');
        if ($name === '') {
            throw new RuntimeException('Walk-in row is missing a shooter name.');
        }

        $email = isset($data['email']) ? trim((string) $data['email']) : '';
        $email = $email === '' ? null : strtolower($email);

        $divisionSlug = strtolower(trim((string) ($data['division_slug'] ?? '')));
        if ($divisionSlug === '') {
            throw new RuntimeException("Walk-in for {$name}: division_slug is required.");
        }
        $division = Division::whereRaw('LOWER(slug) = ?', [$divisionSlug])->first();
        if (! $division) {
            throw new RuntimeException("Walk-in for {$name}: division '{$divisionSlug}' does not exist.");
        }

        $status = strtolower(trim((string) ($data['membership_status'] ?? '')));
        if ($status !== '' && ! in_array($status, RegistrationPricingService::CATEGORIES, true)) {
            throw new RuntimeException("Walk-in for {$name}: membership_status must be one of "
                .implode(', ', RegistrationPricingService::CATEGORIES).".");
        }
        $forcedCategory = $status !== '' ? $status : null;

        $note = trim((string) ($data['note'] ?? ''));
        if ($note === '') {
            throw new RuntimeException("Walk-in for {$name}: note is required so exco has a reason on file.");
        }

        return DB::transaction(function () use ($match, $name, $email, $division, $forcedCategory, $note, $confirmedBy) {
            $user = $this->resolveUser($name, $email);

            // Break the fee breakdown out of the pricing service so we
            // reuse SAPRF-fee + platform-fee logic that respects per-match
            // overrides. We deliberately IGNORE the total_fee / md_net
            // components — the site didn't collect an entry fee for a
            // walk-in, so we override them with 0 / -(deductions).
            $matchDate = $match->match_date ?? now();
            $breakdown = $this->pricing->calculateBreakdown(
                $match,
                $user,
                $matchDate,
                $division->slug,
                $forcedCategory,
            );

            $saprf = (float) $breakdown['saprf_fee'];
            $platform = (float) $breakdown['platform_fee'];
            $mdDeduction = -1 * round($saprf + $platform, 2);

            $registration = MatchRegistration::create([
                'match_id' => $match->id,
                'user_id' => $user->id,
                'registered_by_user_id' => $confirmedBy?->id,
                'division_id' => $division->id,
                'shooter_name' => $name,
                'email' => $email ?? $user->email,
                'membership_fee_category' => $breakdown['category'],
                'fee_amount' => 0,
                'surcharge_amount' => 0,
                'saprf_fee' => $saprf,
                'platform_fee' => $platform,
                'gateway_fee' => 0,
                'md_net_amount' => $mdDeduction,
                'payment_status' => 'paid',
                'registration_status' => 'confirmed',
                'registration_source' => 'walk_in',
                'walk_in_note' => $note,
                'walk_in_confirmed_by' => $confirmedBy?->id,
                'walk_in_confirmed_at' => now(),
                'registered_at' => now(),
            ]);

            AuditLog::create([
                'user_id' => $confirmedBy?->id,
                'actor_type' => $confirmedBy ? 'user' : 'system',
                'action_type' => 'registration.walk_in_confirmed',
                'entity_type' => 'MatchRegistration',
                'entity_id' => $registration->id,
                'old_value' => null,
                'new_value' => [
                    'match_id' => $match->id,
                    'match_name' => $match->name,
                    'shooter' => $name,
                    'user_id' => $user->id,
                    'user_shadow' => $this->isShadowUser($user),
                    'division_slug' => $division->slug,
                    'membership_category' => $breakdown['category'],
                    'saprf_fee' => $saprf,
                    'platform_fee' => $platform,
                    'gateway_fee' => 0.00,
                    'md_deduction' => $mdDeduction,
                    'note' => $note,
                ],
                'reason' => "MD confirmed walk-in for {$name} on match #{$match->id}",
            ]);

            return $registration;
        });
    }

    /**
     * Find or create a User to attach the walk-in registration to.
     * Preference order: email match → canonical name match → shadow user
     * with a synthesized `saprf.walkin` email so the record can still
     * hold a `user_id`.
     */
    private function resolveUser(string $name, ?string $email): User
    {
        if ($email !== null) {
            $existing = User::whereRaw('LOWER(email) = ?', [$email])->first();
            if ($existing) {
                return $existing;
            }

            return User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make(Str::random(40)),
            ]);
        }

        // No email — try to find an existing user by name.
        $lower = strtolower($name);
        $byName = User::whereRaw('LOWER(name) = ?', [$lower])->first();
        if ($byName) {
            return $byName;
        }

        // Shadow user: unique synthesized email that intentionally can't
        // receive mail (subdomain we don't own). Admin can merge into a
        // real account later.
        $slug = Str::slug($name, '.') ?: 'shooter';
        $unique = Str::lower(Str::random(6));
        $shadowEmail = "walkin.{$slug}.{$unique}@saprf.walkin";

        return User::create([
            'name' => $name,
            'email' => $shadowEmail,
            'password' => Hash::make(Str::random(40)),
        ]);
    }

    private function isShadowUser(User $user): bool
    {
        return str_ends_with(strtolower((string) $user->email), '@saprf.walkin');
    }
}
