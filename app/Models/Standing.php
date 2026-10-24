<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class Standing extends Model
{
    protected $fillable = [
        'user_id',
        'series',
        'season',
        'province_id',
        'division_id',
        'points',
        'rank',
        'pool_breakdown',
    ];

    protected function casts(): array
    {
        return [
            'points' => 'decimal:3',
            'pool_breakdown' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(Province::class);
    }

    public function division(): BelongsTo
    {
        return $this->belongsTo(Division::class);
    }

    /**
     * Attach a `rank_label` string attribute to every row in the given
     * Collection — `T1` when two or more rows share the stored `rank`
     * value, plain `"1"` when unique. All three public surfaces that
     * display rank numbers (standings page, homepage widget, shooter
     * profile) consume this helper so the "T1 on one page, 1 and 2 on
     * another" drift the audit found cannot recur.
     *
     * The grouping is scoped per (season, series, province_id,
     * division_id) so a tie in the Overall Open table doesn't bleed
     * into the Factory table.
     *
     * @param  Collection<int, Standing>  $rows
     * @return Collection<int, Standing>  the same instance, mutated in place
     */
    public static function applyTieLabels(Collection $rows): Collection
    {
        $counts = $rows->groupBy(function (self $standing): string {
            return implode('|', [
                (string) ($standing->season ?? ''),
                (string) ($standing->series ?? ''),
                (string) ($standing->province_id ?? ''),
                (string) ($standing->division_id ?? ''),
                (string) ($standing->rank ?? ''),
            ]);
        })->map->count();

        return $rows->each(function (self $standing) use ($counts): void {
            $key = implode('|', [
                (string) ($standing->season ?? ''),
                (string) ($standing->series ?? ''),
                (string) ($standing->province_id ?? ''),
                (string) ($standing->division_id ?? ''),
                (string) ($standing->rank ?? ''),
            ]);

            $tied = ($counts[$key] ?? 1) > 1;
            $rank = (int) $standing->rank;

            $standing->setAttribute('rank_label', $tied ? 'T'.$rank : (string) $rank);
            $standing->setAttribute('is_tied', $tied);
        });
    }

    /**
     * Single-row rank label for the shooter profile — walks the sibling
     * rows in the same scope with one count query.
     *
     * Returns the string the UI should render (`"T1"` or `"1"`), not the
     * raw rank int. Null in → null out (profile shows em-dash instead).
     */
    public static function rankLabelFor(?self $standing): ?string
    {
        if ($standing === null || $standing->rank === null) {
            return null;
        }

        $tied = static::query()
            ->where('season', $standing->season)
            ->where('series', $standing->series)
            ->where(fn ($q) => $standing->province_id === null
                ? $q->whereNull('province_id')
                : $q->where('province_id', $standing->province_id))
            ->where(fn ($q) => $standing->division_id === null
                ? $q->whereNull('division_id')
                : $q->where('division_id', $standing->division_id))
            ->where('rank', $standing->rank)
            ->count() > 1;

        return $tied ? 'T'.(int) $standing->rank : (string) (int) $standing->rank;
    }
}
