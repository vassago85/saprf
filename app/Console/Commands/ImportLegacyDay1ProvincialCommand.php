<?php

namespace App\Console\Commands;

use App\Models\MatchEvent;
use App\Services\ImpactScoring\LegacyDay1ProvincialImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

class ImportLegacyDay1ProvincialCommand extends Command
{
    protected $signature = 'scores:legacy-day1-provincial
        {match : The 2-day national match id}
        {--impact= : Impact match id. Defaults to the id already stored on the national}
        {--csv= : Stage sheet. Stages 1 to --day1-stages are the provincial. Skips the Impact pull}
        {--day1-stages=10 : How many stages, in stage-number order, count as day 1}';

    protected $description = 'Post day 1 of a legacy 2-day national as a provincial where every shooter counts';

    public function handle(LegacyDay1ProvincialImporter $importer): int
    {
        $national = MatchEvent::query()->find((int) $this->argument('match'));
        if ($national === null) {
            $this->error('Match not found.');

            return self::FAILURE;
        }

        $impact = (int) ($this->option('impact') ?: $national->impact_match_id);
        $day1Stages = (int) $this->option('day1-stages');
        $csv = trim((string) $this->option('csv'));

        try {
            if ($csv !== '') {
                $path = $this->resolveCsv($csv);
                $result = $importer->importStageSheet($national, $path, $day1Stages);
            } else {
                $result = $importer->import($national, $impact, $day1Stages);
            }
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Provincial #{$result['sibling_id']} {$result['sibling_name']}");
        $this->info('Day 1 stages: '.implode(', ', $result['day1_stages']));
        $this->info("Scores written: {$result['written']}");
        if ($result['shortened'] !== []) {
            $this->line('Names matched to the national scoreboard: '.implode('; ', $result['shortened']));
        }
        if ($result['non_members'] !== []) {
            $this->line('Non-members, score stays on the match and off the standings: '.implode(', ', $result['non_members']));
        }
        if ($result['unmatched'] !== []) {
            $this->warn('Left off the provincial (name was not unique): '.implode(', ', $result['unmatched']));
        }

        return self::SUCCESS;
    }

    private function resolveCsv(string $csv): string
    {
        if (is_file($csv)) {
            return $csv;
        }

        $fromRoot = base_path($csv);

        return is_file($fromRoot) ? $fromRoot : $csv;
    }
}
