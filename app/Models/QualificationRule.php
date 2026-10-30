<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QualificationRule extends Model
{
    public const MODE_NATIONAL_SERIES_2027 = 'national_series_2027';

    /**
     * 2027 rules: no more than 2 of the best 3 nationals may come from the
     * shooter's home province.
     */
    public const NATIONAL_SERIES_2027_HOME_PROVINCE_CAP = 2;

    protected $fillable = [
        'series',
        'season',
        'scoring_mode',
        'min_out_of_province_matches',
        'best_of_count',
        'total_qualifying_matches',
        'weighted_final_enabled',
        'weighted_final_multiplier',
        'provincial_pool_best_of',
        'provincial_pool_weight_pct',
        'national_pool_best_of',
        'national_pool_weight_pct',
        'national_pool_min_matches',
        'champs_pool_best_of',
        'champs_pool_weight_pct',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'weighted_final_enabled' => 'boolean',
            'weighted_final_multiplier' => 'decimal:2',
            'provincial_pool_weight_pct' => 'decimal:2',
            'national_pool_weight_pct' => 'decimal:2',
            'champs_pool_weight_pct' => 'decimal:2',
        ];
    }

    public function isPooledScoring(): bool
    {
        return $this->scoring_mode === 'weighted_pools';
    }

    /**
     * PRS annual "national log": the shooter's best N regular (national) match
     * percentages PLUS a fixed, non-droppable championship (final) percentage.
     * Max = (best_of_count * 100) + 100.
     */
    public function isAnnualLogWithChamps(): bool
    {
        return $this->scoring_mode === 'best_n_plus_champs';
    }

    /**
     * 2027 national series, signed 29 October 2026.
     *
     * Champion log (max 500): best 3 nationals and/or the PRS International,
     * at most 2 from the home province, plus the SA Championships weighted
     * as one match (max 200). The 14 October draft weighted each
     * championship day separately and did not count the international.
     *
     * Selection log (max 400): the same best 3, plus the championships out
     * of 100. The championships are mandatory for selection. Ties go to the
     * higher championship score, then the skills-stage tiebreak, which
     * does not add points.
     */
    public function isNationalSeries2027(): bool
    {
        return $this->scoring_mode === self::MODE_NATIONAL_SERIES_2027;
    }

    /**
     * Both the PRS annual log and the 2027 national series build the national
     * table from national + final matches only, with provincial matches
     * feeding a separate best-of-N provincial table.
     */
    public function usesNationalLog(): bool
    {
        return $this->isAnnualLogWithChamps() || $this->isNationalSeries2027();
    }

    /**
     * How many regular (national) matches count toward the annual log.
     * Defaults to 3 per the PRS rules.
     */
    public function regularBestOf(): int
    {
        return (int) ($this->best_of_count ?: 3);
    }

    public function totalPoolWeight(): float
    {
        return (float) $this->provincial_pool_weight_pct
            + (float) $this->national_pool_weight_pct
            + (float) $this->champs_pool_weight_pct;
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
