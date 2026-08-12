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
 *   /selection/{series}-policy/{season}      -> specific historical season
 */
class PublicSelectionPolicyController extends Controller
{
    /**
     * @var array<string, array{title: string, current_season: string, historical_seasons: array<int, string>}>
     */
    private const SERIES = [
        'pr22' => [
            'title' => 'PR22 Team Selection',
            'current_season' => '2027',
            'historical_seasons' => ['2026'],
        ],
        'prs' => [
            'title' => 'PRS Team Selection',
            'current_season' => '2026',
            'historical_seasons' => [],
        ],
    ];

    public function show(string $series, ?string $season = null): View
    {
        $series = strtolower($series);
        if (! isset(self::SERIES[$series])) {
            throw new NotFoundHttpException();
        }

        $meta = self::SERIES[$series];
        $season = $season ?: $meta['current_season'];

        $mdPath = base_path("docs/selection/{$series}/{$season}/policy.md");
        if (! is_file($mdPath)) {
            throw new NotFoundHttpException("Selection policy not published for {$series} {$season}.");
        }

        $markdown = (string) file_get_contents($mdPath);
        $html = (new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]))->convert($markdown)->getContent();

        $otherSeasons = array_values(array_filter(
            array_merge([$meta['current_season']], $meta['historical_seasons']),
            fn (string $s) => $s !== $season,
        ));

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
