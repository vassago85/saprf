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

    /**
     * Get the classification date for a season based on admin settings.
     */
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
     */
    public function getAgeBasedCategories(int $age, string $discipline): Collection
    {
        return Category::active()
            ->ageBased()
            ->ordered()
            ->get()
            ->filter(fn (Category $cat) => $cat->matchesAge($age));
    }

    /**
     * Classify a single shooter for a season and discipline.
     * Returns existing classification if already locked.
     */
    public function classifyShooterForSeason(User $user, string $season, string $discipline): SeasonShooterClassification
    {
        $existing = SeasonShooterClassification::where('season', $season)
            ->where('user_id', $user->id)
            ->where('discipline', $discipline)
            ->first();

        if ($existing && $existing->is_locked) {
            return $existing;
        }

        $classificationDate = $this->getClassificationDate($season);
        $age = $user->getAgeOn($classificationDate);
        $lockEnabled = (bool) $this->settingsService->get('season_locked_age_categories', true);

        $classification = SeasonShooterClassification::updateOrCreate(
            ['season' => $season, 'user_id' => $user->id, 'discipline' => $discipline],
            [
                'classification_date' => $classificationDate,
                'age_on_classification_date' => $age,
                'is_locked' => $lockEnabled,
            ],
        );

        // Sync age-based categories
        if ($age !== null) {
            $ageCategories = $this->getAgeBasedCategories($age, $discipline);
            $classification->categories()->sync($ageCategories->pluck('id'));
        }

        return $classification;
    }

    /**
     * Bulk classify all shooters with a date_of_birth for a season/discipline.
     */
    public function classifyAllShootersForSeason(string $season, string $discipline): int
    {
        $count = 0;

        User::whereNotNull('date_of_birth')
            ->where('is_active', true)
            ->chunk(100, function ($users) use ($season, $discipline, &$count) {
                foreach ($users as $user) {
                    $this->classifyShooterForSeason($user, $season, $discipline);
                    $count++;
                }
            });

        return $count;
    }

    /**
     * Admin override for a shooter's season classification.
     */
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
