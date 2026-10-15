<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * One row per year a shooter shot for South Africa at an IPRF world
 * championship (or comparable national event).
 *
 * The `awarded_colours` flag marks the ONE appearance that granted the
 * shooter their Protea Colours — a career-once honour. Subsequent
 * appearances by the same shooter are national-team appearances that do
 * NOT re-award colours.
 *
 * Invariant: at most one appearance per user has `awarded_colours = true`.
 * Enforced by NationalTeamAppearanceController (MySQL doesn't support
 * partial unique indexes). If you write code that flips the flag on a
 * row, use the `awardColoursTo()` helper below so the invariant is
 * preserved.
 */
class NationalTeamAppearance extends Model
{
    public const ROLE_SHOOTER = 'shooter';

    public const ROLE_CAPTAIN = 'captain';

    public const ROLE_MANAGER = 'manager';

    public const ROLE_OFFICIAL = 'official';

    public const ROLES = [
        self::ROLE_SHOOTER => 'Shooter',
        self::ROLE_CAPTAIN => 'Team Captain',
        self::ROLE_MANAGER => 'Team Manager',
        self::ROLE_OFFICIAL => 'Official',
    ];

    /**
     * Display order on team-year rosters + honour-roll "best role" picks:
     * managers run the team (0), captains lead the squad (1), shooters
     * fill it out (2), officials bring up the rear (3).
     */
    public const ROLE_PRIORITY = [
        self::ROLE_MANAGER => 0,
        self::ROLE_CAPTAIN => 1,
        self::ROLE_SHOOTER => 2,
        self::ROLE_OFFICIAL => 3,
    ];

    public static function rolePriority(?string $role): int
    {
        return self::ROLE_PRIORITY[$role] ?? self::ROLE_PRIORITY[self::ROLE_SHOOTER];
    }

    protected $fillable = [
        'user_id',
        'year',
        'division_id',
        'division_label',
        'role',
        'national_team_id',
        'championship_name',
        'host_country',
        'placing',
        'selection_cycle_id',
        'awarded_colours',
        'appeared_at',
        'recorded_by',
        'notes',
    ];

    /** Cache key for the public /sa-team page's data pass (guest-only). */
    public const PUBLIC_PAGE_CACHE_KEY = 'sa_team.public.data';

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'placing' => 'integer',
            'awarded_colours' => 'boolean',
            'appeared_at' => 'date',
        ];
    }

    protected static function booted(): void
    {
        // Any appearance write invalidates the public /sa-team cache.
        static::saved(fn () => Cache::forget(self::PUBLIC_PAGE_CACHE_KEY));
        static::deleted(fn () => Cache::forget(self::PUBLIC_PAGE_CACHE_KEY));
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    public function nationalTeam(): BelongsTo
    {
        return $this->belongsTo(NationalTeam::class);
    }

    public function isManager(): bool
    {
        return $this->role === self::ROLE_MANAGER;
    }

    public function isCaptain(): bool
    {
        return $this->role === self::ROLE_CAPTAIN;
    }

    public function isOfficial(): bool
    {
        return in_array($this->role, [self::ROLE_MANAGER, self::ROLE_OFFICIAL], true);
    }

    public function selectionCycle(): BelongsTo
    {
        return $this->belongsTo(SelectionCycle::class);
    }

    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function scopeAwardedColours(Builder $query): Builder
    {
        return $query->where('awarded_colours', true);
    }

    public function divisionName(): ?string
    {
        return $this->division?->name ?? $this->division_label;
    }

    public function hostCountryName(): ?string
    {
        if (! $this->host_country) {
            return null;
        }

        return self::HOST_COUNTRIES[$this->host_country] ?? $this->host_country;
    }

    /**
     * Bulk-fetch years each user has shot for SA. Scoreboards call this once
     * per request with every visible user_id to render the inline "SA YYYY"
     * chips without an N+1 relation load.
     *
     * Returns `[user_id => [2026, 2024, ...]]` with years sorted newest first.
     * Users with no appearances are omitted from the map (callers use
     * `$map[$id] ?? []`).
     *
     * @param  array<int>  $userIds
     * @return array<int, array<int, int>>
     */
    public static function yearsForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = self::query()
            ->whereIn('user_id', $userIds)
            ->orderByDesc('year')
            ->get(['user_id', 'year']);

        $map = [];
        foreach ($rows as $row) {
            $uid = (int) $row->user_id;
            $yr = (int) $row->year;
            // Dedupe within a year (two championships in the same year on
            // the same user still render as one chip).
            $map[$uid] ??= [];
            if (! in_array($yr, $map[$uid], true)) {
                $map[$uid][] = $yr;
            }
        }

        return $map;
    }

    /**
     * Richer badge payload for the same bulk set — one entry per user with
     * the years list PLUS a precomputed tooltip summarising their most
     * recent appearance (e.g. "Team Manager · 2026 IPRF World Championship").
     *
     * Returned shape:
     *   [user_id => [
     *       'years' => [2026, 2024],
     *       'tooltip' => 'Open · 2026 IPRF World Championship (Centrefire)',
     *       'is_manager' => false,
     *   ]]
     *
     * @param  array<int>  $userIds
     * @return array<int, array{years: array<int,int>, tooltip: string, is_manager: bool}>
     */
    public static function badgeDataForUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $rows = self::query()
            ->whereIn('user_id', $userIds)
            ->orderByDesc('year')
            ->orderByDesc('appeared_at')
            ->get(['user_id', 'year', 'championship_name', 'division_label', 'host_country', 'role']);

        $out = [];
        foreach ($rows as $row) {
            $uid = (int) $row->user_id;
            $yr = (int) $row->year;

            if (! isset($out[$uid])) {
                // Prefer the structural role for the badge label; fall
                // back to the division text when a historical row
                // predates the role column.
                $role = $row->role ?? self::ROLE_SHOOTER;
                $primaryLabel = match ($role) {
                    self::ROLE_MANAGER => 'Team Manager',
                    self::ROLE_CAPTAIN => trim(($row->division_label ? $row->division_label.' · ' : '').'Captain'),
                    self::ROLE_OFFICIAL => 'Official',
                    default => $row->division_label,
                };
                $labelParts = array_filter([
                    $primaryLabel,
                    $row->championship_name,
                ]);
                $out[$uid] = [
                    'years' => [],
                    'tooltip' => implode(' · ', $labelParts),
                    'is_manager' => $role === self::ROLE_MANAGER,
                ];
            }

            if (! in_array($yr, $out[$uid]['years'], true)) {
                $out[$uid]['years'][] = $yr;
            }
        }

        return $out;
    }

    /**
     * IPRF & regional-championship host countries. Broader than
     * User::COUNTRY_OPTIONS (which is limited to South Africa + likely
     * diaspora residences) because IPRF worlds have been held in Sweden,
     * Norway, USA, Finland, France, Poland, Czech Republic, and many other
     * countries a South African could never plausibly reside in. Codes
     * are ISO 3166-1 alpha-2. When a new championship happens somewhere
     * that isn't listed, add the entry here rather than falling back to
     * the raw two-letter code.
     */
    public const HOST_COUNTRIES = [
        'ZA' => 'South Africa',
        'US' => 'United States',
        'GB' => 'United Kingdom',
        'AU' => 'Australia',
        'NZ' => 'New Zealand',
        'CA' => 'Canada',
        'SE' => 'Sweden',
        'NO' => 'Norway',
        'FI' => 'Finland',
        'DK' => 'Denmark',
        'IS' => 'Iceland',
        'DE' => 'Germany',
        'FR' => 'France',
        'ES' => 'Spain',
        'IT' => 'Italy',
        'PT' => 'Portugal',
        'NL' => 'Netherlands',
        'BE' => 'Belgium',
        'CH' => 'Switzerland',
        'AT' => 'Austria',
        'PL' => 'Poland',
        'CZ' => 'Czech Republic',
        'SK' => 'Slovakia',
        'HU' => 'Hungary',
        'RO' => 'Romania',
        'BG' => 'Bulgaria',
        'GR' => 'Greece',
        'HR' => 'Croatia',
        'SI' => 'Slovenia',
        'EE' => 'Estonia',
        'LV' => 'Latvia',
        'LT' => 'Lithuania',
        'IE' => 'Ireland',
        'UA' => 'Ukraine',
        'TR' => 'Turkey',
        'IL' => 'Israel',
        'AE' => 'United Arab Emirates',
        'JP' => 'Japan',
        'KR' => 'South Korea',
        'AR' => 'Argentina',
        'BR' => 'Brazil',
        'CL' => 'Chile',
        'MX' => 'Mexico',
        'NA' => 'Namibia',
        'BW' => 'Botswana',
        'ZW' => 'Zimbabwe',
        'XX' => 'Other',
    ];
}
