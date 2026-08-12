<?php

namespace App\Http\Controllers\Selection;

use App\Http\Controllers\Controller;
use Illuminate\View\View;
use League\CommonMark\GithubFlavoredMarkdownConverter;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Publishes verbatim SAPRF selection policy documents to the public web.
 *
 * Markdown files under docs/selection/{series}/{season}/policy.md are
 * authoritative and rendered with GitHub-flavored CommonMark. Anyone can
 * view — no auth — because these are governance documents SAPRF publishes
 * for members and the public.
 *
 * Route contract:
 *   /selection/{series}-policy               -> current season for the series
 *                                               (or latest historical if no
 *                                                current season is published)
 *   /selection/{series}-policy/{season}      -> specific historical season
 */
class PublicSelectionPolicyController extends Controller
{
    /**
     * Series catalog. `current_season` may be null (e.g. PRS between cycles);
     * in that case the default view is the most recent historical season.
     *
     * @var array<string, array{title: string, current_season: string|null, historical_seasons: array<int, string>}>
     */
    private const SERIES = [
        'pr22' => [
            'title' => 'PR22 Team Selection (Rimfire)',
            'current_season' => '2027',
            'historical_seasons' => [],
        ],
        'prs' => [
            'title' => 'PRS Team Selection (Centrefire)',
            'current_season' => null,
            'historical_seasons' => ['2026'],
        ],
    ];

    public function show(string $series, ?string $season = null): View
    {
        $series = strtolower($series);
        if (! isset(self::SERIES[$series])) {
            throw new NotFoundHttpException();
        }

        $meta = self::SERIES[$series];
        $season = $season ?: ($meta['current_season'] ?? ($meta['historical_seasons'][0] ?? null));
        if (! $season) {
            throw new NotFoundHttpException("No selection policy published for {$series} yet.");
        }

        $mdPath = base_path("docs/selection/{$series}/{$season}/policy.md");
        if (! is_file($mdPath)) {
            throw new NotFoundHttpException("Selection policy not published for {$series} {$season}.");
        }

        $markdown = (string) file_get_contents($mdPath);
        $html = (new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]))->convert($markdown)->getContent();

        $allSeasons = array_values(array_filter(array_merge(
            [$meta['current_season']],
            $meta['historical_seasons'],
        )));
        $otherSeasons = array_values(array_filter($allSeasons, fn (string $s) => $s !== $season));

        return view('selection.public.policy', [
            'series_key' => $series,
            'series_title' => strtoupper($series),
            'title' => $meta['title'],
            'season' => $season,
            'is_current' => $season === $meta['current_season'],
            'other_seasons' => $otherSeasons,
            'html' => $html,
            'source_path' => "docs/selection/{$series}/{$season}/policy.md",
        ]);
    }
}
