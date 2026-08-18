<?php

namespace App\Services\Announcements;

use App\Enums\AudienceMode;
use App\Enums\AudienceType;
use App\Models\AnnouncementAudience;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\SavedDistributionList;
use App\Models\Score;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Turn a set of composer rules into a concrete list of `users.id` values.
 *
 * The resolver is the single source of truth for "who receives this
 * announcement" — everything else (preview, freeze, dispatch, delivery
 * accounting) reads its output. It is intentionally the first thing
 * we build and the first thing we test because the whole feature
 * fails safe or unsafe based on whether this is right.
 *
 * Rules of composition, in order:
 *   1. Union every user id produced by an `include`-mode rule.
 *   2. Subtract every user id produced by an `exclude`-mode rule.
 *   3. Drop soft-deleted users (defence in depth — they should not
 *      appear in any query anyway, but this guarantees it).
 *
 * Empty include set = zero recipients. There is deliberately NO implicit
 * "everyone" fallback — the composer must always pick at least one rule.
 */
class AudienceResolver
{
    /**
     * How deep saved-list expansion can recurse before we bail out.
     * In practice one level is all the composer ever produces, but the
     * guard keeps a malicious rules payload from DoS-ing the worker.
     */
    private const MAX_SAVED_LIST_DEPTH = 5;

    /**
     * @param  Collection<int, AnnouncementAudience>|iterable<array>  $rules
     * @return Collection<int, int> User ids, unique, ascending.
     */
    public function resolve(iterable $rules): Collection
    {
        $normalised = $this->normaliseRules($rules);

        $include = collect();
        $exclude = collect();

        foreach ($normalised as $rule) {
            $ids = $this->resolveRule($rule['type'], $rule['value'], depth: 0);

            if ($rule['mode'] === AudienceMode::Include) {
                $include = $include->merge($ids);
            } else {
                $exclude = $exclude->merge($ids);
            }
        }

        if ($include->isEmpty()) {
            return collect();
        }

        $excludeSet = $exclude->unique()->flip();

        return $include
            ->unique()
            ->reject(fn (int $id) => $excludeSet->has($id))
            ->pipe(fn (Collection $ids) => $this->dropSoftDeleted($ids))
            ->sort()
            ->values();
    }

    /**
     * Same as resolve() but capped: returns count + first N User rows
     * for the composer preview panel.
     */
    public function preview(iterable $rules, int $sample = 20): AudiencePreview
    {
        $ids = $this->resolve($rules);

        $sampleUsers = User::query()
            ->whereIn('id', $ids->take($sample)->all())
            ->orderBy('name')
            ->get();

        return new AudiencePreview($ids->count(), $sampleUsers);
    }

    /**
     * Accepts either persisted AnnouncementAudience rows or a raw array
     * of `['type' => ..., 'value' => ..., 'mode' => ...]` maps so the
     * live composer can preview without saving.
     *
     * @param  iterable<AnnouncementAudience|array>  $rules
     * @return array<int, array{type: AudienceType, value: array, mode: AudienceMode}>
     */
    private function normaliseRules(iterable $rules): array
    {
        $out = [];

        foreach ($rules as $rule) {
            if ($rule instanceof AnnouncementAudience) {
                $out[] = [
                    'type' => $rule->type,
                    'value' => is_array($rule->value) ? $rule->value : [],
                    'mode' => $rule->mode,
                ];
                continue;
            }

            $type = $rule['type'] ?? null;
            $mode = $rule['mode'] ?? 'include';
            $value = $rule['value'] ?? [];

            $out[] = [
                'type' => $type instanceof AudienceType ? $type : AudienceType::from((string) $type),
                'value' => is_array($value) ? $value : [],
                'mode' => $mode instanceof AudienceMode ? $mode : AudienceMode::from((string) $mode),
            ];
        }

        return $out;
    }

    /**
     * Dispatch a single rule to its resolver.
     *
     * @return Collection<int, int>
     */
    private function resolveRule(AudienceType $type, array $value, int $depth): Collection
    {
        return match ($type) {
            AudienceType::All => $this->resolveAll(),
            AudienceType::ActiveMembers => $this->resolveActiveMembers(),
            AudienceType::MembershipType => $this->resolveMembershipType($value),
            AudienceType::FeeTier => $this->resolveFeeTier($value),
            AudienceType::Division => $this->resolveDivision($value),
            AudienceType::Series => $this->resolveSeries($value),
            AudienceType::Role => $this->resolveRole($value),
            AudienceType::Club => $this->resolveClub($value),
            AudienceType::Province => $this->resolveProvince($value),
            AudienceType::Individual => $this->resolveIndividuals($value),
            AudienceType::SavedList => $this->resolveSavedList($value, $depth),
            AudienceType::MatchEntrants => $this->resolveMatchEntrants($value),
        };
    }

    /**
     * "Everyone" for the federation broadcast means every user who has
     * a real (non-`free`) membership record — this deliberately still
     * includes lapsed / expired members, unlike active_members. Legacy
     * free-marked rows with a genuine SAPRF number are included too,
     * matching the reasoning in Membership::isActiveMember().
     */
    private function resolveAll(): Collection
    {
        return User::query()
            ->join('memberships', 'memberships.user_id', '=', 'users.id')
            ->where(function ($q) {
                $q->where('memberships.membership_type', '!=', 'free')
                    ->orWhere(function ($qq) {
                        $qq->whereNotNull('memberships.saprf_number')
                            ->where('memberships.saprf_number', 'not like', 'SAPRF-IMPORT-%')
                            ->whereIn('memberships.payment_status', ['paid', 'waived']);
                    });
            })
            ->pluck('users.id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * "Active members" is the same predicate `Membership::isActiveMember()`
     * enforces in PHP, expressed via the reusable `activeMembers()` query
     * scope so the resolver, the admin membership list, and any future
     * export always pick the same cohort.
     */
    private function resolveActiveMembers(): Collection
    {
        return User::query()
            ->whereHas('membership', fn ($q) => $q->activeMembers())
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
    }

    private function resolveMembershipType(array $value): Collection
    {
        $type = $value['membership_type'] ?? null;

        if (! $type) {
            return collect();
        }

        return User::query()
            ->whereHas('membership', fn ($q) => $q->where('membership_type', $type))
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
    }

    private function resolveFeeTier(array $value): Collection
    {
        $tierId = $value['fee_tier_id'] ?? null;

        if (! $tierId) {
            return collect();
        }

        return User::query()
            ->whereHas('membership', fn ($q) => $q->where('fee_tier_id', $tierId))
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
    }

    private function resolveDivision(array $value): Collection
    {
        $divisionId = $value['division_id'] ?? null;

        if (! $divisionId) {
            return collect();
        }

        return User::query()
            ->where('division_id', $divisionId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * "Users who have engaged with this series in the given season."
     *
     * We treat both a match registration AND a recorded score as evidence
     * of engagement: upcoming entrants and already-competed shooters both
     * count. This is what the spec called Centerfire/Rimfire — a match
     * attribute, not a user profile flag.
     */
    private function resolveSeries(array $value): Collection
    {
        $series = $value['series'] ?? null;
        $season = $value['season'] ?? (string) now()->year;

        if (! $series) {
            return collect();
        }

        $matchIds = MatchEvent::query()
            ->where('series', $series)
            ->where('season', $season)
            ->pluck('id');

        if ($matchIds->isEmpty()) {
            return collect();
        }

        $registrationUserIds = MatchRegistration::query()
            ->whereIn('match_id', $matchIds)
            ->whereNotNull('user_id')
            ->pluck('user_id');

        $scoreUserIds = Score::query()
            ->whereIn('match_id', $matchIds)
            ->whereNotNull('user_id')
            ->pluck('user_id');

        return $registrationUserIds
            ->merge($scoreUserIds)
            ->unique()
            ->map(fn ($id) => (int) $id)
            ->values();
    }

    private function resolveRole(array $value): Collection
    {
        $role = $value['role'] ?? null;

        if (! $role) {
            return collect();
        }

        return User::query()
            ->role($role) // Spatie scope
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
    }

    private function resolveClub(array $value): Collection
    {
        $clubId = $value['club_id'] ?? null;

        if (! $clubId) {
            return collect();
        }

        return User::query()
            ->where('club_id', $clubId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
    }

    private function resolveProvince(array $value): Collection
    {
        $provinceId = $value['province_id'] ?? null;

        if (! $provinceId) {
            return collect();
        }

        return User::query()
            ->where('province_id', $provinceId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * The composer text input for "Named individuals" is a plain
     * comma-separated string like "881, 882, 883". Depending on where
     * the payload comes from (live preview via Alpine → JSON body,
     * form submit → PHP $_POST array, saved-list JSON row → array of
     * ints), the `user_ids` field can arrive as:
     *
     *   - a raw string:      "881,882 883\n884"
     *   - an array of ints:  [881, 882]
     *   - an array of str:   ["881", "882"]
     *   - null / missing / empty
     *
     * We normalise here so callers don't have to pre-process, and so
     * legacy persisted rows keep working. Non-numeric tokens are silently
     * dropped — the composer surfaces this by showing 0 recipients.
     */
    private function resolveIndividuals(array $value): Collection
    {
        $raw = $value['user_ids'] ?? null;

        if ($raw === null || $raw === '' || $raw === []) {
            return collect();
        }

        $tokens = is_array($raw)
            ? $raw
            : preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);

        $clean = collect($tokens ?: [])
            ->map(fn ($id) => is_string($id) ? trim($id) : $id)
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($clean->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $clean)
            ->pluck('id')
            ->map(fn ($id) => (int) $id);
    }

    /**
     * "Everyone entered in a specific match." This is what backs the
     * Match Director bulletin flow — the MD picks their match, the
     * resolver expands it to the set of users who should receive the
     * message.
     *
     * Value shape (from the composer / MD form):
     *   { "match_id": 42, "status_scope": ["confirmed", "waitlisted"] }
     *
     * Rules mirror `MatchAnnouncementController::resolveRecipients()`
     * exactly so unifying the two channels doesn't change who gets
     * notified:
     *
     *   1. Registrations in `status_scope` are considered (default
     *      confirmed + waitlisted). Cancelled / pending / withdrawn
     *      are excluded — mailing them would be spam.
     *   2. Managed juniors carry a placeholder email; their message
     *      routes to the parent instead. Parent resolution: prefer
     *      `MatchRegistration::registered_by_user_id`, fall back to
     *      `User::parent_id`.
     *   3. Duplicates are dropped so a parent with two junior entrants
     *      only gets one bulletin.
     */
    private function resolveMatchEntrants(array $value): Collection
    {
        $matchId = $value['match_id'] ?? null;

        if (! $matchId || ! is_numeric($matchId)) {
            return collect();
        }

        $rawScope = $value['status_scope'] ?? ['confirmed', 'waitlisted'];
        $statusScope = is_array($rawScope) && $rawScope !== []
            ? array_values(array_filter($rawScope, 'is_string'))
            : ['confirmed', 'waitlisted'];

        $registrations = MatchRegistration::query()
            ->with(['user:id,is_managed_account,parent_id,managed_relationship', 'user.parent:id'])
            ->where('match_id', (int) $matchId)
            ->whereIn('registration_status', $statusScope)
            ->whereNotNull('user_id')
            ->get(['id', 'user_id', 'registered_by_user_id', 'registration_status']);

        $userIds = $registrations
            ->map(function (MatchRegistration $registration) {
                $entrant = $registration->user;

                if (! $entrant) {
                    return null;
                }

                // Non-managed adults: message the entrant directly.
                if (! $entrant->isManaged()) {
                    return $entrant->id;
                }

                // Managed junior: route to the account that registered
                // them if available, otherwise fall back to their parent
                // link. If neither exists the row silently drops
                // (mirrors the legacy warning in
                // MatchAnnouncementController::resolveRecipients()).
                $parentId = $registration->registered_by_user_id
                    ?? optional($entrant->parent)->id;

                return $parentId ? (int) $parentId : null;
            })
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        return $userIds;
    }

    private function resolveSavedList(array $value, int $depth): Collection
    {
        if ($depth >= self::MAX_SAVED_LIST_DEPTH) {
            throw new RuntimeException('Saved distribution list recursion exceeded ' . self::MAX_SAVED_LIST_DEPTH . ' levels.');
        }

        $listId = $value['list_id'] ?? null;

        if (! $listId) {
            return collect();
        }

        $list = SavedDistributionList::query()->find($listId);

        if (! $list) {
            return collect();
        }

        $include = collect();
        $exclude = collect();

        foreach ((array) $list->rules as $rule) {
            $type = $rule['type'] ?? null;
            $mode = $rule['mode'] ?? 'include';
            $ruleValue = $rule['value'] ?? [];

            if (! $type) {
                continue;
            }

            $ids = $this->resolveRule(
                AudienceType::from((string) $type),
                is_array($ruleValue) ? $ruleValue : [],
                $depth + 1,
            );

            if (($mode instanceof AudienceMode ? $mode : AudienceMode::from((string) $mode)) === AudienceMode::Include) {
                $include = $include->merge($ids);
            } else {
                $exclude = $exclude->merge($ids);
            }
        }

        if ($include->isEmpty()) {
            return collect();
        }

        $excludeSet = $exclude->unique()->flip();

        return $include
            ->unique()
            ->reject(fn (int $id) => $excludeSet->has($id))
            ->values();
    }

    /**
     * @param  Collection<int, int>  $ids
     * @return Collection<int, int>
     */
    private function dropSoftDeleted(Collection $ids): Collection
    {
        if ($ids->isEmpty()) {
            return $ids;
        }

        $alive = User::query()
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->flip();

        return $ids->filter(fn (int $id) => $alive->has($id))->values();
    }
}
