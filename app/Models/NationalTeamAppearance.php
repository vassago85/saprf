<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
    protected $fillable = [
        'user_id',
        'year',
        'division_id',
        'division_label',
        'championship_name',
        'host_country',
        'placing',
        'selection_cycle_id',
        'awarded_colours',
        'appeared_at',
        'recorded_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'placing' => 'integer',
            'awarded_colours' => 'boolean',
            'appeared_at' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
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
            ->get(['user_id', 'year', 'championship_name', 'division_label', 'host_country']);

        $out = [];
        foreach ($rows as $row) {
            $uid = (int) $row->user_id;
            $yr = (int) $row->year;

            if (! isset($out[$uid])) {
                $labelParts = array_filter([
                    $row->division_label,
                    $row->championship_name,
                ]);
                $out[$uid] = [
                    'years' => [],
                    'tooltip' => implode(' · ', $labelParts),
                    'is_manager' => strcasecmp((string) $row->division_label, 'Team Manager') === 0,
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
