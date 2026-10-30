<?php

namespace App\Http\Controllers;

use App\Services\SettingsService;
use App\Support\MarkdownDocument;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Serves the SAPRF sport rulebooks (Rules & Regulations, Divisions, PR22
 * Rimfire Series) as HTML with the same "cool" chrome we use for the
 * legal / governance documents — sticky ToC, clause-number gutter,
 * reading-progress bar, print-to-PDF, deep-link anchors, scroll spy.
 *
 * The markdown files under `docs/rules/*.md` are the source of truth for
 * on-screen rendering. The federation's authoritative PDFs live at
 * `public/publications/*.pdf` and are linked from every rendered page's
 * header (Download original PDF) so members can grab the signed copy.
 *
 * All three pages flow through the shared MarkdownDocument pipeline, so
 * the ToC extraction, clause-number splitter, table-scroll wrapper and
 * heading-anchor injector apply consistently. A rule that never uses
 * N.N.N. clause numbering (like Divisions) just gets a no-op splitter.
 */
class RulesController extends Controller
{
    /**
     * Catalog of published rulebooks. Adding a new rulebook is a three-line
     * change here + a matching markdown file under `docs/rules/`.
     *
     * @var array<string, array{
     *   title: string,
     *   kicker: string,
     *   subtitle: string,
     *   version: string|null,
     *   effective_date: string|null,
     *   blurb: string,
     *   md: string,
     *   pdf: string|null,
     *   view: string,
     *   archived_by?: string,
     * }>
     *
     * `archived_by` names the rulebook that superseded this one. Once that
     * rulebook is published, this page stays live at its URL but is marked
     * Archived and links to its replacement.
     */
    private const RULEBOOKS = [
        'rules-and-regulations' => [
            'title' => 'SAPRF Rules & Regulations',
            'kicker' => 'SAPRF · Sport Rules',
            'subtitle' => 'The full rulebook for SAPRF-sanctioned Precision Rifle matches — course design, range construction, competitor equipment, match management, course of fire, scoring and arbitration.',
            'version' => '2.1',
            'effective_date' => '19 February 2024',
            'blurb' => 'Reproduced verbatim from the SAPRF Rules & Regulations. In case of any conflict with the official SAPRF publication, the original PDF is authoritative.',
            'md' => 'docs/rules/rules-and-regulations.md',
            'pdf' => 'publications/saprf-rules-and-regulations.pdf',
            'view' => 'rules.rules-and-regulations',
        ],
        'divisions' => [
            'title' => 'SAPRF Divisions',
            'kicker' => 'SAPRF · Sport Rules',
            'subtitle' => 'Open · Ladies · Senior · Junior · Mil/LEO · Limited · Factory · Classic',
            'version' => null,
            'effective_date' => 'August 2023',
            'blurb' => 'The equipment and eligibility rules for every SAPRF division and subdivision.',
            'md' => 'docs/rules/divisions.md',
            'pdf' => 'publications/saprf-divisions.pdf',
            'view' => 'rules.divisions',
            'archived_by' => 'national-series-2027',
        ],
        'pr22-rimfire-series' => [
            'title' => 'PR22 Rimfire Series Rules',
            'kicker' => 'SAPRF · Sport Rules',
            'subtitle' => 'The rimfire-specific overlay to the main SAPRF rulebook — divisions, ammunition, series structure, log-score weighting and national colours.',
            'version' => '1',
            'effective_date' => '12 December 2025',
            'blurb' => 'Reproduced verbatim from the SAPRF PR22 Rimfire Series Rules. In case of any conflict with the official SAPRF publication, the original PDF is authoritative.',
            'md' => 'docs/rules/pr22-rimfire-series.md',
            'pdf' => 'publications/pr22-rimfire-series-rules.pdf',
            'view' => 'rules.pr22-rimfire-series',
            'archived_by' => 'national-series-2027',
        ],
        'national-series-2027' => [
            'title' => 'SAPRF 2027 National Series, Championships and Qualifying for Selection',
            'kicker' => 'SAPRF · Sport Rules',
            'subtitle' => 'Centrefire and PR22 Rimfire · divisions, nationals, the championships as one match, the 500-point ranking and the 400-point selection score.',
            'version' => null,
            'effective_date' => '29 October 2026',
            'blurb' => 'Reproduced from the SAPRF 2027 national series rules signed on 29 October 2026. In case of any conflict with the official SAPRF publication, the original PDF is authoritative.',
            'md' => 'docs/rules/national-series-2027.md',
            'pdf' => 'publications/saprf-national-series-2027.pdf',
            'view' => 'rules.national-series-2027',
        ],
    ];

    public function rulesAndRegulations(): View
    {
        return $this->render('rules-and-regulations');
    }

    public function divisions(): View
    {
        return $this->render('divisions');
    }

    public function pr22RimfireSeries(): View
    {
        return $this->render('pr22-rimfire-series');
    }

    /**
     * Parked until SAPRF announces the 2027 rules: the public gets a 404,
     * owners/admins get a preview marked Draft.
     */
    public function nationalSeries2027(Request $request, SettingsService $settings): View
    {
        $published = $settings->nationalSeries2027Published();

        if (! $published && ! $request->user()?->hasAnyRole(['owner', 'admin'])) {
            throw new NotFoundHttpException();
        }

        return view(self::RULEBOOKS['national-series-2027']['view'], $this->props('national-series-2027') + [
            'status' => $published
                ? ['label' => 'Current', 'tone' => 'emerald']
                : ['label' => 'Draft · not yet published', 'tone' => 'amber'],
        ]);
    }

    /**
     * @return array{
     *   title: string,
     *   kicker: string,
     *   subtitle: string,
     *   version: string|null,
     *   effective_date: string|null,
     *   blurb: string,
     *   html: string,
     *   toc: array<int, array{id:string, text:string, children:array<int, array{id:string, text:string}>}>,
     *   last_updated: ?Carbon,
     *   source_path: string,
     *   pdf_url: string|null,
     * }
     */
    private function props(string $slug): array
    {
        $spec = self::RULEBOOKS[$slug];

        $abs = base_path($spec['md']);
        $markdown = is_file($abs) ? (string) file_get_contents($abs) : '';
        $rendered = MarkdownDocument::render($markdown);

        return [
            'title' => $spec['title'],
            'kicker' => $spec['kicker'],
            'subtitle' => $spec['subtitle'],
            'version' => $spec['version'],
            'effective_date' => $spec['effective_date'],
            'blurb' => $spec['blurb'],
            'html' => $rendered['html'],
            'toc' => $rendered['toc'],
            'last_updated' => is_file($abs)
                ? Carbon::createFromTimestamp(filemtime($abs))
                : null,
            'source_path' => $spec['md'],
            'pdf_url' => $spec['pdf'] && is_file(public_path($spec['pdf'])) ? asset($spec['pdf']) : null,
        ];
    }

    private function render(string $slug): View
    {
        $spec = self::RULEBOOKS[$slug];

        return view($spec['view'], $this->props($slug) + $this->archiveState($spec));
    }

    /**
     * Only the 2027 series supersedes anything today, and it counts as
     * published once `saprf:national-series-2027 publish` has run.
     *
     * @return array{status: array{label: string, tone: string}, superseded_by: array{title: string, url: string}|null}
     */
    private function archiveState(array $spec): array
    {
        $replacement = $spec['archived_by'] ?? null;

        if ($replacement !== 'national-series-2027' || ! app(SettingsService::class)->nationalSeries2027Published()) {
            return ['status' => ['label' => 'Current', 'tone' => 'emerald'], 'superseded_by' => null];
        }

        return [
            'status' => ['label' => 'Archived', 'tone' => 'stone'],
            'superseded_by' => [
                'title' => self::RULEBOOKS[$replacement]['title'],
                'url' => route('rules.national-series-2027'),
            ],
        ];
    }
}
