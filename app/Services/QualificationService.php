<?php

namespace App\Services;

use App\Models\QualificationRule;
use App\Models\Score;
use App\Models\User;

class QualificationService
{
    public function getQualificationStatus(User $user, string $series, string $season): array
    {
        $rule = QualificationRule::query()
            ->where('series', $series)
            ->where('season', $season)
            ->first();

        $required = $rule?->min_out_of_province_matches ?? 0;

        $completed = $this->countOutOfProvinceMatches($user, $series, $season);

        return [
            'required' => $required,
            'completed' => $completed,
            'qualified' => $required > 0 && $completed >= $required,
            'remaining' => max(0, $required - $completed),
        ];
    }

    public function isQualifiedForFinals(User $user, string $series, string $season): bool
    {
        return $this->getQualificationStatus($user, $series, $season)['qualified'];
    }

    private function countOutOfProvinceMatches(User $user, string $series, string $season): int
    {
        return Score::query()
            ->where('user_id', $user->id)
            ->where('status', 'valid')
            ->whereHas('match', function ($query) use ($user, $series, $season) {
                $query->where('series', $series)
                    ->where('series_level', 'national')
                    ->where(function ($q) use ($season) {
                        $q->where('season', $season)
                            ->orWhereRaw('YEAR(match_date) = ?', [$season]);
                    })
                    ->where('province_id', '!=', $user->province_id);
            })
            ->distinct('match_id')
            ->count('match_id');
    }
}
