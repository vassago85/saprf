<?php

namespace App\Services;

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Imports the "upcoming matches & entries" export from the old
 * precisionrifle.co.za site into this platform:
 *
 *   1. Resolves each old-site event to an existing MatchEvent (by name + date,
 *      or an explicit override map).
 *   2. Sets each match's entry fee from the sheet (active_member_fee, with the
 *      member/non-member/lapsed columns derived from the global surcharges).
 *   3. Assigns the match director: gives the resolved user the match_director
 *      role and makes them own (created_by) the match.
 *   4. Resolves every entrant to a user via memberships.saprf_number, creating
 *      a stub user + membership when none exists.
 *   5. Creates a confirmed + paid MatchRegistration for each entrant, skipping
 *      anyone already registered (idempotent — safe to re-run).
 *
 * The service always writes; the calling command wraps it in a transaction and
 * rolls back for dry runs, so the dry-run report reflects exactly what a real
 * run would do.
 */
class UpcomingEntriesImporter
{
    /** @var array<string, User|null> keyed by SAPRF number */
    private array $userBySaprf = [];

    /** @var array<int, MatchEvent> keyed by old_event_id */
    private array $matchByOldId = [];

    public function __construct(
        private readonly RegistrationPricingService $pricing,
        private readonly MembershipValidationService $membershipValidation,
        private readonly SettingsService $settings,
    ) {}

    /**
     * @param  array  $dataset  decoded upcoming_entries_2026.json
     * @param  array  $overrides  ['matches' => [oldId => matchId], 'directors' => [oldId => userId]]
     * @param  array{fees:bool, md:bool, registrations:bool}  $phases
     */
    public function run(array $dataset, array $overrides, array $phases): array
    {
        $report = [
            'matches' => [],
            'fees' => [],
            'directors' => [],
            'users' => ['found' => 0, 'created' => 0, 'details' => []],
            'registrations' => ['created' => 0, 'skipped_existing' => 0, 'skipped_no_match' => 0, 'details' => []],
            'unresolved' => ['matches' => [], 'directors' => [], 'divisions' => []],
        ];

        $matches = $dataset['matches'] ?? [];
        $entrants = $dataset['entrants'] ?? [];

        $this->resolveMatches($matches, $overrides['matches'] ?? [], $report);

        if ($phases['fees']) {
            $this->applyFees($matches, $report);
        }

        if ($phases['md']) {
            $this->assignDirectors($matches, $entrants, $overrides['directors'] ?? [], $report);
        }

        if ($phases['registrations']) {
            $this->createRegistrations($entrants, $report);
        }

        return $report;
    }

    // ── Phase 1: match resolution ──

    private function resolveMatches(array $matches, array $overrideMap, array &$report): void
    {
        $candidates = MatchEvent::query()
            ->whereNotNull('match_date')
            ->whereYear('match_date', 2026)
            ->get();

        foreach ($matches as $m) {
            $oldId = (int) $m['old_event_id'];
            $resolved = null;
            $note = '';

            if (isset($overrideMap[(string) $oldId]) || isset($overrideMap[$oldId])) {
                $matchId = (int) ($overrideMap[(string) $oldId] ?? $overrideMap[$oldId]);
                $resolved = $candidates->firstWhere('id', $matchId) ?? MatchEvent::find($matchId);
                $note = 'override';
            }

            if (! $resolved) {
                [$resolved, $note] = $this->matchByNameAndDate($m, $candidates);
            }

            if ($resolved) {
                $this->matchByOldId[$oldId] = $resolved;
                $report['matches'][] = [
                    'old_event_id' => $oldId,
                    'status' => 'matched',
                    'match_id' => $resolved->id,
                    'sheet_name' => $m['name'],
                    'platform_name' => $resolved->name,
                    'note' => $note,
                ];
            } else {
                $report['matches'][] = [
                    'old_event_id' => $oldId,
                    'status' => 'unmatched',
                    'match_id' => null,
                    'sheet_name' => $m['name'],
                    'platform_name' => null,
                    'note' => $note ?: 'no candidate on '.($m['match_date'] ?? '?'),
                ];
                $report['unresolved']['matches'][] = [
                    'old_event_id' => $oldId,
                    'name' => $m['name'],
                    'date' => $m['match_date'] ?? null,
                ];
            }
        }
    }

    /**
     * @return array{0: ?MatchEvent, 1: string}
     */
    private function matchByNameAndDate(array $m, $candidates): array
    {
        $date = $m['match_date'] ?? null;
        if (! $date) {
            return [null, 'no date in sheet'];
        }

        $target = $this->normalize($m['name']);
        $sameDate = $candidates->filter(
            fn (MatchEvent $c) => $c->match_date?->toDateString() === $date
        );

        if ($sameDate->isEmpty()) {
            return [null, 'no match on '.$date];
        }

        $exact = $sameDate->first(fn (MatchEvent $c) => $this->normalize($c->name) === $target);
        if ($exact) {
            return [$exact, 'name+date exact'];
        }

        // Fuzzy: one side contains the other. Only accept when unambiguous.
        $contains = $sameDate->filter(function (MatchEvent $c) use ($target) {
            $n = $this->normalize($c->name);
            return $n !== '' && (str_contains($n, $target) || str_contains($target, $n));
        });
        if ($contains->count() === 1) {
            return [$contains->first(), 'name+date fuzzy'];
        }

        if ($sameDate->count() === 1) {
            return [$sameDate->first(), 'date-only (single candidate)'];
        }

        return [null, $sameDate->count().' candidates on '.$date.' — needs override'];
    }

    private function normalize(string $s): string
    {
        return preg_replace('/[^a-z0-9]/', '', strtolower($s)) ?? '';
    }

    // ── Phase 2: fees ──

    private function applyFees(array $matches, array &$report): void
    {
        $nonMemberSurcharge = (float) $this->settings->get('non_member_surcharge', 0);
        $lapsedSurcharge = (float) $this->settings->get('lapsed_member_surcharge', 0);

        foreach ($matches as $m) {
            $oldId = (int) $m['old_event_id'];
            $match = $this->matchByOldId[$oldId] ?? null;
            if (! $match) {
                continue;
            }

            $entryFee = $m['entry_fee'];
            $juniorFee = $m['junior_fee'] ?? null;

            if ($entryFee === null && $juniorFee === null) {
                $report['fees'][] = [
                    'match_id' => $match->id,
                    'action' => 'skip',
                    'note' => 'no fee in sheet ("not set")',
                ];
                continue;
            }

            $old = (float) $match->active_member_fee;

            if ($entryFee !== null) {
                $base = (float) $entryFee;
                $match->active_member_fee = $base;
                $match->non_member_fee = $base + $nonMemberSurcharge;
                $match->lapsed_member_fee = $base + $lapsedSurcharge;
            }

            // Only store a junior fee when it actually differs from the entry
            // fee — an identical junior price is just the normal fee.
            if ($juniorFee !== null && $entryFee !== null && (float) $juniorFee !== (float) $entryFee) {
                $match->junior_fee = (float) $juniorFee;
            }

            if (! $match->isDirty()) {
                $report['fees'][] = [
                    'match_id' => $match->id,
                    'action' => 'unchanged',
                    'note' => 'already R'.number_format($old, 0),
                ];
                continue;
            }

            $report['fees'][] = [
                'match_id' => $match->id,
                'action' => 'set',
                'old' => $old,
                'new' => (float) $match->active_member_fee,
                'junior' => $match->junior_fee !== null ? (float) $match->junior_fee : null,
            ];

            $match->save();
        }
    }

    // ── Phase 3: match directors ──

    private function assignDirectors(array $matches, array $entrants, array $overrideMap, array &$report): void
    {
        $saprfByName = $this->buildNameToSaprf($entrants);

        foreach ($matches as $m) {
            $oldId = (int) $m['old_event_id'];
            $match = $this->matchByOldId[$oldId] ?? null;
            $mdName = $m['match_director'] ?? null;

            if (! $match) {
                continue;
            }
            if (! $mdName) {
                $report['directors'][] = ['old_event_id' => $oldId, 'status' => 'no_director_in_sheet'];
                continue;
            }

            $user = $this->resolveDirector($oldId, $mdName, $saprfByName, $overrideMap);

            if (! $user) {
                $report['directors'][] = [
                    'old_event_id' => $oldId,
                    'match_id' => $match->id,
                    'md_name' => $mdName,
                    'status' => 'unresolved',
                ];
                $report['unresolved']['directors'][] = ['old_event_id' => $oldId, 'name' => $mdName];
                continue;
            }

            $actions = [];

            if (! $user->hasRole('match_director')) {
                $user->assignRole('match_director');
                $actions[] = 'role+match_director';
            }

            if ((int) $match->created_by !== (int) $user->id) {
                $match->created_by = $user->id;
                $actions[] = 'owner->'.$user->name;
            }

            // Refresh the free-text display fields from the sheet.
            if ($m['match_director'] && $match->match_director !== $m['match_director']) {
                $match->match_director = $m['match_director'];
                $actions[] = 'md_name';
            }
            if (! empty($m['match_director_contact']) && $match->match_director_contact !== $m['match_director_contact']) {
                $match->match_director_contact = $m['match_director_contact'];
                $actions[] = 'md_contact';
            }

            if ($match->isDirty()) {
                $match->save();
            }

            $report['directors'][] = [
                'old_event_id' => $oldId,
                'match_id' => $match->id,
                'md_name' => $mdName,
                'user_id' => $user->id,
                'status' => $actions ? implode(', ', $actions) : 'already set',
            ];
        }
    }

    private function resolveDirector(int $oldId, string $mdName, array $saprfByName, array $overrideMap): ?User
    {
        $override = $overrideMap[(string) $oldId] ?? $overrideMap[$oldId] ?? null;
        if ($override !== null) {
            return $this->resolveOverrideUser($override);
        }

        // Reliable path: the MD is also an entrant, so we have their SAPRF #.
        $norm = $this->normalize($mdName);
        if (isset($saprfByName[$norm]) && count($saprfByName[$norm]) === 1) {
            $user = $this->userForSaprf($saprfByName[$norm][0]);
            if ($user) {
                return $user;
            }
        }

        // Fallback: unambiguous name match against existing users.
        $byName = User::whereRaw('LOWER(name) = ?', [strtolower(trim($mdName))])->get();
        if ($byName->count() === 1) {
            return $byName->first();
        }

        return null;
    }

    /**
     * Resolve a director override value, which may be a numeric platform user
     * id, an email address, or a "saprf:NNN" membership-number reference — the
     * last two being unambiguous ways to point at a specific person.
     */
    private function resolveOverrideUser(int|string $value): ?User
    {
        if (is_int($value) || ctype_digit((string) $value)) {
            return User::find((int) $value);
        }

        $value = trim((string) $value);

        if (str_contains($value, '@')) {
            return User::where('email', $value)->first();
        }

        if (str_starts_with($value, 'saprf:')) {
            $saprf = trim(substr($value, strlen('saprf:')));

            return Membership::where('saprf_number', $saprf)->first()?->user;
        }

        return null;
    }

    /**
     * @return array<string, array<int, string>> normalized name => [saprf, ...]
     */
    private function buildNameToSaprf(array $entrants): array
    {
        $map = [];
        foreach ($entrants as $e) {
            $norm = $this->normalize($e['name']);
            $saprf = (string) $e['saprf_number'];
            $map[$norm] ??= [];
            if (! in_array($saprf, $map[$norm], true)) {
                $map[$norm][] = $saprf;
            }
        }

        return $map;
    }

    // ── Phase 4/5: entrants → users + registrations ──

    private function createRegistrations(array $entrants, array &$report): void
    {
        foreach ($entrants as $e) {
            $oldId = (int) $e['old_event_id'];
            $match = $this->matchByOldId[$oldId] ?? null;

            if (! $match) {
                $report['registrations']['skipped_no_match']++;
                continue;
            }

            $user = $this->userForSaprf((string) $e['saprf_number'], $e, $report);

            if ($match->userRegistration($user)) {
                $report['registrations']['skipped_existing']++;
                continue;
            }

            $division = Division::where('slug', $e['division'])->first();
            if (! $division) {
                $report['unresolved']['divisions'][] = $e['division'];
                continue;
            }

            $fees = $this->buildFees($match, $user, (float) ($e['fee'] ?? 0), $e['division'] ?? null);

            MatchRegistration::query()->create([
                'match_id' => $match->id,
                'user_id' => $user->id,
                'division_id' => $division->id,
                'shooter_name' => $e['name'],
                'email' => $user->email,
                'phone' => $user->phone,
                'membership_fee_category' => $fees['category'],
                'fee_amount' => $fees['total'],
                'surcharge_amount' => $fees['surcharge'],
                'saprf_fee' => $fees['saprf_fee'],
                'platform_fee' => $fees['platform_fee'],
                'gateway_fee' => $fees['gateway_fee'],
                'md_net_amount' => $fees['md_net'],
                'payment_status' => 'paid',
                'registration_status' => 'confirmed',
                'registered_at' => now(),
            ]);

            $report['registrations']['created']++;
        }
    }

    /**
     * Fee split for an imported (already-paid) entry. The sheet fee is
     * authoritative for the total; the SAPRF/platform cuts come from the
     * live rate settings, and the MD net is whatever is left over.
     */
    private function buildFees(MatchEvent $match, User $user, float $sheetFee, ?string $divisionSlug = null): array
    {
        $breakdown = $this->pricing->calculateBreakdown($match, $user, $match->match_date ?? now(), $divisionSlug);

        $base = (float) $breakdown['base_fee'];
        $total = $sheetFee > 0 ? $sheetFee : (float) $breakdown['total_fee'];
        $surcharge = max(0.0, round($total - $base, 2));
        $gatewayPct = (float) ($breakdown['rates']['gateway_pct'] ?? 0);
        $gatewayFlat = (float) ($breakdown['rates']['gateway_flat'] ?? 0);
        $gateway = round($total * ($gatewayPct / 100) + $gatewayFlat, 2);
        $mdNet = round($total - (float) $breakdown['saprf_fee'] - (float) $breakdown['platform_fee'] - $surcharge - $gateway, 2);

        return [
            'category' => $breakdown['category'],
            'total' => $total,
            'surcharge' => $surcharge,
            'saprf_fee' => (float) $breakdown['saprf_fee'],
            'platform_fee' => (float) $breakdown['platform_fee'],
            'gateway_fee' => $gateway,
            'md_net' => $mdNet,
        ];
    }

    /**
     * Resolve (and cache) a user by SAPRF number, creating a stub user +
     * membership when no account exists and entrant context is provided.
     */
    private function userForSaprf(string $saprf, ?array $entrant = null, ?array &$report = null): ?User
    {
        if (array_key_exists($saprf, $this->userBySaprf)) {
            $user = $this->userBySaprf[$saprf];
            if ($user) {
                return $user;
            }
        }

        $membership = Membership::where('saprf_number', $saprf)->first();
        if ($membership && $membership->user) {
            if ($report !== null) {
                $report['users']['found']++;
            }

            return $this->userBySaprf[$saprf] = $membership->user;
        }

        if ($entrant === null) {
            return $this->userBySaprf[$saprf] = null;
        }

        $user = $this->createStubUser($saprf, $entrant);
        if ($report !== null) {
            $report['users']['created']++;
            $report['users']['details'][] = ['saprf' => $saprf, 'name' => $entrant['name'], 'email' => $user->email];
        }

        return $this->userBySaprf[$saprf] = $user;
    }

    private function createStubUser(string $saprf, array $entrant): User
    {
        $email = 'saprf-'.$saprf.'@import.saprf.local';
        while (User::where('email', $email)->exists()) {
            $email = 'saprf-'.$saprf.'-'.Str::lower(Str::random(4)).'@import.saprf.local';
        }

        $divisionId = Division::where('slug', $entrant['division'])->value('id');

        $user = User::create([
            'name' => $entrant['name'],
            'email' => $email,
            'password' => Hash::make(Str::random(40)),
            'division_id' => $divisionId,
            'is_active' => true,
            'email_verified_at' => null,
            'must_change_password' => true,
        ]);

        if (! $user->hasRole('member')) {
            $user->assignRole('member');
        }

        $isFree = ($entrant['membership_type'] ?? '') === 'free';

        Membership::create([
            'user_id' => $user->id,
            'saprf_number' => $saprf,
            'membership_type' => $isFree ? 'free' : 'paid',
            'status' => 'active',
            'payment_status' => $isFree ? 'unpaid' : 'paid',
            'start_date' => Carbon::now()->startOfDay(),
            'expiry_date' => $isFree ? null : Carbon::now()->addYear()->startOfDay(),
        ]);

        return $user->fresh();
    }
}
