<?php

namespace App\Services;

use App\Models\Category;
use App\Models\SeasonShooterClassification;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class SeasonClassificationService
{
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function getClassificationDate(string $season): Carbon
    {
        $mode = $this->settingsService->get('age_classification_date_mode', 'first_day_of_calendar_year');

        return match ($mode) {
            'season_start_date' => Carbon::create((int) $season, 1, 1),
            'custom_date' => Carbon::parse($this->settingsService->get('age_classification_custom_date', "{$season}-01-01")),
            default => Carbon::create((int) $season, 1, 1),
        };
    }

    /**
     * Determine which age-based categories a shooter qualifies for.
     * Junior thresholds differ by series: PRS (centrefire) = 21, PR22 (rimfire) = 18.
     * When no series is given, uses the higher (more inclusive) PRS threshold.
     */
    public function getAgeBasedCategories(int $age, ?string $series = null): Collection
    {
        $juniorMaxAge = match (strtoupper($series ?? 'PRS')) {
            'PR22' => (int) $this->settingsService->get('pr22_junior_max_age', 18),
            default => (int) $this->settingsService->get('prs_junior_max_age', 21),
        };
        $seniorMinAge = (int) $this->settingsService->get('senior_min_age', 55);

        $slugs = [];

        if ($age <= $juniorMaxAge) {
            $slugs[] = 'junior';
        }

        if ($age >= $seniorMinAge) {
            $slugs[] = 'senior';
        }

        if (empty($slugs)) {
            return collect();
        }

        return Category::active()->whereIn('slug', $slugs)->ordered()->get();
    }

    /**
     * Check if a given age qualifies as junior for a specific series.
     */
    public function isJuniorForSeries(int $age, string $series): bool
    {
        $maxAge = match (strtoupper($series)) {
            'PR22' => (int) $this->settingsService->get('pr22_junior_max_age', 18),
            default => (int) $this->settingsService->get('prs_junior_max_age', 21),
        };

        return $age <= $maxAge;
    }

    /**
     * Classify a single shooter for a season.
     * Returns existing classification if already locked.
     */
    public function classifyShooterForSeason(User $user, string $season): SeasonShooterClassification
    {
        $existing = SeasonShooterClassification::where('season', $season)
            ->where('user_id', $user->id)
            ->first();

        if ($existing && $existing->is_locked) {
            return $existing;
        }

        $classificationDate = $this->getClassificationDate($season);
        $age = $user->getAgeOn($classificationDate);
        $lockEnabled = (bool) $this->settingsService->get('season_locked_age_categories', true);

        $classification = SeasonShooterClassification::updateOrCreate(
            ['season' => $season, 'user_id' => $user->id],
            [
                'classification_date' => $classificationDate,
                'age_on_classification_date' => $age,
                'is_locked' => $lockEnabled,
            ],
        );

        if ($age !== null) {
            $ageCategories = $this->getAgeBasedCategories($age);
            $classification->categories()->sync($ageCategories->pluck('id'));
        }

        return $classification;
    }

    /**
     * Bulk classify all shooters with a date_of_birth for a season.
     */
    public function classifyAllShootersForSeason(string $season): int
    {
        $count = 0;

        User::whereNotNull('date_of_birth')
            ->where('is_active', true)
            ->chunk(100, function ($users) use ($season, &$count) {
                foreach ($users as $user) {
                    $this->classifyShooterForSeason($user, $season);
                    $count++;
                }
            });

        return $count;
    }

    public function overrideClassification(
        SeasonShooterClassification $classification,
        array $categoryIds,
        string $reason,
        User $admin,
    ): void {
        $oldCategories = $classification->categories()->pluck('categories.id')->toArray();

        $classification->update([
            'override_applied' => true,
            'override_reason' => $reason,
        ]);

        $classification->categories()->sync($categoryIds);

        $this->auditLogService->log(
            $admin,
            'season_classification.override',
            'SeasonShooterClassification',
            $classification->id,
            ['categories' => $oldCategories],
            ['categories' => $categoryIds, 'reason' => $reason],
            $reason,
        );
    }
}
