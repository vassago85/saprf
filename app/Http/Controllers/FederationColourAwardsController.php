<?php

namespace App\Http\Controllers;

use App\Models\Division;
use App\Services\FederationColourAwards;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FederationColourAwardsController extends Controller
{
    public function __construct(
        private readonly FederationColourAwards $awards,
    ) {}

    public function index(Request $request): View
    {
        [$series, $season, $divisionId] = $this->settings($request);
        $report = $this->awards->build($series, $season, $divisionId);

        return view('reports.federation-colour-awards', [
            'series' => $series,
            'seriesLabel' => FederationColourAwards::seriesLabel($series),
            'season' => $season,
            'seasons' => $this->seasons(),
            'divisionId' => $divisionId,
            'catalog' => $this->awards->catalog($series, $season),
            'report' => $report,
        ]);
    }

    public function csv(Request $request): StreamedResponse
    {
        [$series, $season, $divisionId] = $this->settings($request);
        $rows = $this->awards->flat($series, $season, $divisionId);
        $filename = "SAPRF-Federation-Colour-Awards-{$series}-{$season}.csv";

        return response()->streamDownload(function () use ($rows, $series, $season) {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, [
                'Series',
                'Season',
                'Division',
                'Rank',
                'Shooter',
                'Member Number',
                'Raw Score',
                'Weighted Percentage',
                'Award',
                'Pending Award',
            ]);

            foreach ($rows as $row) {
                fputcsv($handle, [
                    $series,
                    $season,
                    $row['division'],
                    $row['rank'],
                    $row['name'],
                    $row['saprf_number'] ?? '',
                    number_format((float) $row['raw_score'], 2, '.', ''),
                    $row['weighted_percentage'] !== null
                        ? number_format((float) $row['weighted_percentage'], 2, '.', '')
                        : '',
                    $row['award'] ?? '',
                    $row['pending_award'] ?? '',
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * @return array{0: string, 1: string, 2: int|null}
     */
    private function settings(Request $request): array
    {
        $series = strtoupper((string) $request->input('series', 'PR22'));
        if (! in_array($series, FederationColourAwards::SERIES, true)) {
            $series = 'PR22';
        }

        $season = (string) $request->input('season', now()->year);
        $divisionId = $request->input('division');
        $divisionId = $divisionId === null || $divisionId === ''
            ? null
            : (int) $divisionId;
        if ($divisionId !== null && ! Division::query()->whereKey($divisionId)->exists()) {
            $divisionId = null;
        }

        return [$series, $season, $divisionId];
    }

    /**
     * @return Collection<int, string>
     */
    private function seasons(): Collection
    {
        return collect(range(now()->year, now()->year - 4))->map(fn ($year) => (string) $year);
    }
}
