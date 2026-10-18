<?php

namespace App\Support\Dashboard\Shooter;

use App\Models\AmmoLoad;
use App\Models\Barrel;
use App\Models\MatchRegistration;
use App\Models\RifleConfiguration;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * Per-rifle dope stats shown in the "My rifles" panel.
 *
 * Each entry contains:
 *   • avg MV (fps) from the rifle's most recent active ammo load velocity
 *   • SD (fps) from AmmoLoad::measured_sd_fps (saved by the ladder/string
 *     analyser when a confirmation string is pinned)
 *   • rounds this season — summed MatchRegistration::shot_count filtered to
 *     the user's registrations with this rifle in the current year
 *   • lifetime barrel rounds + install date, from the currently-installed
 *     (non-retired) Barrel attached to the rifle. No "barrel life limit" is
 *     tracked on the model, so no progress bar is rendered — the UI shows
 *     "no limit set" to be honest about missing data.
 *
 * Returns `null` for a rifle when there's genuinely nothing to show (no
 * ammo load, no rounds this season, no barrel on it). The view then
 * renders the rifle name + badge only.
 */
final class RifleDopeBuilder
{
    /**
     * @param  iterable<RifleConfiguration>  $rifles
     * @return array<int, array<string, mixed>>
     */
    public function build(User $user, iterable $rifles, ?string $season = null): array
    {
        $season = $season ?: (string) Carbon::now()->year;
        $out = [];

        foreach ($rifles as $rifle) {
            $out[(int) $rifle->id] = $this->buildOne($user, $rifle, $season);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOne(User $user, RifleConfiguration $rifle, string $season): array
    {
        $load = $this->activeAmmoLoad($rifle);
        $barrel = $this->currentBarrel($rifle);
        $roundsSeason = $this->roundsThisSeason($user, $rifle, $season);

        $mv = $load && $load->velocity ? (int) round((float) $load->velocity) : null;
        $sd = $load && $load->measured_sd_fps !== null ? (float) $load->measured_sd_fps : null;

        return [
            'rifle_id' => (int) $rifle->id,
            'has_data' => $mv !== null || $sd !== null || $roundsSeason > 0 || $barrel !== null,
            'ammo_label' => $this->ammoLabel($load),
            'avg_mv' => $mv,
            'sd' => $sd,
            'sd_n' => $load?->measured_sd_n ? (int) $load->measured_sd_n : null,
            'rounds_season' => $roundsSeason,
            'barrel' => $barrel ? [
                'label' => $barrel->label,
                'maker' => $barrel->maker,
                'round_count' => (int) $barrel->round_count,
                'installed_on' => $barrel->installed_on?->format('M Y'),
            ] : null,
            'barrel_lifetime' => (int) $rifle->total_barrel_rounds,
        ];
    }

    private function activeAmmoLoad(RifleConfiguration $rifle): ?AmmoLoad
    {
        return AmmoLoad::query()
            ->where('rifle_configuration_id', $rifle->id)
            ->where('is_active', true)
            ->orderByDesc('measured_sd_at')
            ->orderByDesc('updated_at')
            ->first();
    }

    private function currentBarrel(RifleConfiguration $rifle): ?Barrel
    {
        return Barrel::query()
            ->where('rifle_configuration_id', $rifle->id)
            ->whereNull('retired_on')
            ->orderByDesc('installed_on')
            ->first();
    }

    private function roundsThisSeason(User $user, RifleConfiguration $rifle, string $season): int
    {
        return (int) MatchRegistration::query()
            ->where('user_id', $user->id)
            ->where('rifle_configuration_id', $rifle->id)
            ->whereNotNull('shot_count')
            ->whereHas('match', function ($q) use ($season) {
                $q->where(function ($inner) use ($season) {
                    $inner->where('season', $season)->orWhereYear('match_date', $season);
                });
            })
            ->sum('shot_count');
    }

    private function ammoLabel(?AmmoLoad $load): ?string
    {
        if (! $load) {
            return null;
        }

        $parts = array_filter([
            $load->bullet_make,
            $load->bullet_weight ? $load->bullet_weight.'gr' : null,
            $load->bullet_model,
        ]);

        $label = implode(' ', $parts);

        return $label === '' ? ($load->nickname ?: null) : $label;
    }
}
