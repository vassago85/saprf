<?php

namespace App\Console\Commands;

use App\Models\QualificationRule;
use App\Models\User;
use App\Services\SettingsService;
use App\Services\StandingsCalculationService;
use Illuminate\Console\Command;

/**
 * Switches the parked 2027 national series rules on (or back off).
 *
 *   status     what is currently live
 *   publish    show the rules document at /rules/national-series-2027, on
 *              /documents and in document search
 *   unpublish  park the document again (owner/admin preview only)
 *   activate   point the PRS + PR22 2027 qualification rules at the
 *              national_series_2027 scoring mode and rebuild 2027 standings
 */
class NationalSeries2027Command extends Command
{
    private const SEASON = '2027';

    private const SERIES = ['PRS', 'PR22'];

    protected $signature = 'saprf:national-series-2027
        {action : status | publish | unpublish | activate}
        {--force : Skip the confirmation prompt on activate}';

    protected $description = 'Publish and activate the 2027 SAPRF national series rules';

    public function handle(SettingsService $settings, StandingsCalculationService $standings): int
    {
        return match ($this->argument('action')) {
            'status' => $this->status($settings),
            'publish' => $this->setPublished($settings, true),
            'unpublish' => $this->setPublished($settings, false),
            'activate' => $this->activate($standings),
            default => $this->invalidAction(),
        };
    }

    private function status(SettingsService $settings): int
    {
        $this->line('Rules document: '.($settings->nationalSeries2027Published() ? 'published' : 'parked (owner/admin preview only)'));

        foreach (self::SERIES as $series) {
            $rule = QualificationRule::where('series', $series)->where('season', self::SEASON)->first();
            $this->line(sprintf(
                '%s %s scoring: %s',
                $series,
                self::SEASON,
                $rule ? ($rule->isNationalSeries2027() ? 'active (national_series_2027)' : "not active ({$rule->scoring_mode})") : 'not active (no rule)',
            ));
        }

        return self::SUCCESS;
    }

    private function setPublished(SettingsService $settings, bool $published): int
    {
        $settings->set(
            SettingsService::NATIONAL_SERIES_2027_PUBLISHED,
            $published ? '1' : '0',
            'Show the 2027 national series rules document publicly',
        );

        $this->info($published
            ? 'Published: '.route('rules.national-series-2027')
            : 'Parked: the 2027 rules document is now owner/admin preview only.');

        return self::SUCCESS;
    }

    private function activate(StandingsCalculationService $standings): int
    {
        $existing = QualificationRule::whereIn('series', self::SERIES)->where('season', self::SEASON)->get()->keyBy('series');

        foreach (self::SERIES as $series) {
            $rule = $existing->get($series);
            $this->line(sprintf(
                '%s %s: %s -> national_series_2027 (best 3 nationals, max %d home province, 2-day championships)',
                $series,
                self::SEASON,
                $rule ? $rule->scoring_mode : 'no rule',
                QualificationRule::NATIONAL_SERIES_2027_HOME_PROVINCE_CAP,
            ));
        }

        if (! $this->option('force') && ! $this->confirm('Switch these rules and rebuild the 2027 standings?')) {
            $this->warn('Nothing changed.');

            return self::FAILURE;
        }

        $createdBy = User::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->orderBy('id')->value('id')
            ?? User::query()->min('id');

        foreach (self::SERIES as $series) {
            $rule = QualificationRule::firstOrNew(['series' => $series, 'season' => self::SEASON]);
            $rule->fill([
                'scoring_mode' => QualificationRule::MODE_NATIONAL_SERIES_2027,
                'best_of_count' => 3,
                'min_out_of_province_matches' => 1,
                'total_qualifying_matches' => 4,
                'weighted_final_enabled' => false,
            ]);
            $rule->created_by ??= $createdBy;
            $rule->save();

            $standings->recalculateSeasonStandings($series, self::SEASON);
            $standings->recalculateProvincialStandings($series, self::SEASON);
            $this->info("{$series} ".self::SEASON.' activated and standings rebuilt.');
        }

        return self::SUCCESS;
    }

    private function invalidAction(): int
    {
        $this->error('Action must be one of: status, publish, unpublish, activate.');

        return self::FAILURE;
    }
}
