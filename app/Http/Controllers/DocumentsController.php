<?php

namespace App\Http\Controllers;

use App\Support\DocumentSearch;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The public "Documents" landing page — a single index of every governance,
 * policy, and legal document SAPRF publishes for members and the public.
 *
 * The catalog is intentionally hand-curated (not scanned from disk) so the
 * federation controls the presentation order, categories, and human-readable
 * descriptions. Each entry points at an already-published route (selection
 * policies, terms, privacy, …) — this controller does not render document
 * content itself, only the directory.
 *
 * To publish a new document, see docs/publishing-documents.md for the full
 * step-by-step guide. The short version:
 *
 *   1. Drop the source markdown under docs/{category}/{slug}.md.
 *   2. Add a route + controller method that renders it via the shared
 *      App\Support\MarkdownDocument pipeline (see LegalController or
 *      RulesController for reference).
 *   3. Add a Blade view that wraps the rendered HTML in <x-legal-document>
 *      so the page gets the sticky ToC, clause gutter, print button and
 *      deep-link chrome.
 *   4. Add an entry to $this->catalog() below so the /documents index
 *      surfaces it.
 *   5. Add an entry to App\Support\DocumentSearch::CORPUS so the new
 *      document participates in the cross-document search at
 *      /documents/search?q=… — omit this step and members won't find
 *      the document by keyword.
 *   6. (Optional) If SAPRF has a signed PDF original, put it under
 *      public/publications/<file>.pdf and expose it via the meta slot on
 *      the rendered page — do NOT link the PDF directly from the index,
 *      the HTML render is the canonical entry point.
 */
class DocumentsController extends Controller
{
    public function index(DocumentSearch $search): View
    {
        return view('documents.index', [
            'categories' => $this->catalog(),
            'corpus_size' => $search->corpusSize(),
        ]);
    }

    /**
     * Cross-document search. Loads every corpus markdown, scores each
     * H2 section against the query, and renders the top hits with
     * deep-link anchors into the rendered HTML pages. Runs entirely on
     * disk — no external search infra required — because the whole
     * corpus is a couple hundred KB of markdown.
     *
     * `q` is validated as a plain string (max 200 chars) and always
     * echoed back through Blade's `{{ }}` auto-escaping, so this route
     * is safe against reflected XSS.
     */
    public function search(Request $request, DocumentSearch $search): View
    {
        $query = mb_substr(trim((string) $request->query('q', '')), 0, 200);
        $results = $query === '' ? [] : $search->search($query);

        return view('documents.search', [
            'query' => $query,
            'results' => $results,
            'corpus_size' => $search->corpusSize(),
        ]);
    }

    /**
     * @return array<int, array{
     *     heading: string,
     *     blurb: string,
     *     items: array<int, array{
     *         title: string,
     *         subtitle: string|null,
     *         description: string,
     *         url: string,
     *         badge: array{label: string, tone: string}|null,
     *         last_updated: ?Carbon,
     *         new_tab?: bool,
     *     }>
     * }>
     */
    private function catalog(): array
    {
        return [
            [
                'heading' => 'Help & Information',
                'blurb' => 'Getting-started guides for members, shooters and clubs.',
                'items' => [
                    [
                        'title' => 'Frequently Asked Questions',
                        'subtitle' => 'Membership, matches, divisions & your SAPRF number',
                        'description' => 'Short answers to the questions we get most often — whether you need a SAPRF number to shoot, how to become a full member, what a Primary Club is, how divisions work, and where to find your SAPRF number.',
                        'url' => route('faq.index'),
                        'badge' => ['label' => 'Start here', 'tone' => 'emerald'],
                        'last_updated' => $this->docMtime('docs/faq.md'),
                    ],
                ],
            ],
            [
                'heading' => 'Rules & Regulations',
                'blurb' => 'The rulebooks that govern SAPRF-sanctioned Precision Rifle competition — course of fire, equipment, divisions and safety. Each page renders with a sticky ToC, deep-link anchors and a print/save-as-PDF option; the original signed PDF is linked from every rulebook header.',
                'items' => [
                    [
                        'title' => 'SAPRF Rules & Regulations',
                        'subtitle' => 'v2.1 · February 2024',
                        'description' => 'The full rulebook for SAPRF-sanctioned Precision Rifle matches. Covers course design, range construction, competitor equipment, match structure, stage officials, the course of fire, scoring, penalties, disqualifications and arbitration.',
                        'url' => route('rules.regulations'),
                        'badge' => ['label' => 'Current', 'tone' => 'emerald'],
                        'last_updated' => $this->docMtime('docs/rules/rules-and-regulations.md'),
                    ],
                    [
                        'title' => 'SAPRF Divisions',
                        'subtitle' => 'Open · Limited · Factory · Classic',
                        'description' => 'The equipment and eligibility rules for each SAPRF division and subdivision, including Ladies, Senior, Junior and Mil/LEO Open, together with Limited (.308), Factory and Classic Division definitions.',
                        'url' => route('rules.divisions'),
                        'badge' => ['label' => 'Current', 'tone' => 'emerald'],
                        'last_updated' => $this->docMtime('docs/rules/divisions.md'),
                    ],
                    [
                        'title' => 'PR22 Rimfire Series Rules',
                        'subtitle' => 'v1 · December 2025',
                        'description' => 'The rimfire-specific overlay to the main SAPRF rulebook: divisions eligible for PR22, ammunition restrictions, National Provincial + National 2-day + SA Championship series structure, log-score weighting and national colours criteria.',
                        'url' => route('rules.pr22-rimfire'),
                        'badge' => ['label' => 'Current', 'tone' => 'emerald'],
                        'last_updated' => $this->docMtime('docs/rules/pr22-rimfire-series.md'),
                    ],
                ],
            ],
            [
                'heading' => 'Team Selection',
                'blurb' => 'How SAPRF selects South Africa\'s international precision-rifle teams for IPRF-sanctioned World Championships.',
                'items' => [
                    [
                        'title' => 'PR22 Team Selection (Rimfire)',
                        'subtitle' => '2027 Cycle',
                        'description' => 'Eligibility, participation and scoring rules for the SAPRF PR22 (Rimfire) team that will represent South Africa at the 2027 IPRF World Championships.',
                        'url' => route('selection.policy.public', ['series' => 'pr22']),
                        'badge' => ['label' => 'Current', 'tone' => 'emerald'],
                        'last_updated' => $this->docMtime('docs/selection/pr22/2027/policy.md'),
                    ],
                    [
                        'title' => 'PRS Team Selection (Centrefire)',
                        'subtitle' => '2026 Cycle · Historical',
                        'description' => 'The published selection process used to pick the SAPRF Centrefire (PRS) team for the 2026 IPRF Centrefire World Championships. Provided for reference; the team has already been selected.',
                        'url' => route('selection.policy.public', ['series' => 'prs', 'season' => '2026']),
                        'badge' => ['label' => 'Historical', 'tone' => 'stone'],
                        'last_updated' => $this->docMtime('docs/selection/prs/2026/policy.md'),
                    ],
                ],
            ],
            [
                'heading' => 'Legal & Governance',
                'blurb' => 'The binding terms and privacy commitments that govern your use of the SAPRF platform and membership.',
                'items' => [
                    [
                        'title' => 'Constitution & Memorandum of Incorporation',
                        'subtitle' => 'The founding document of SAPPRF',
                        'description' => 'The Memorandum of Incorporation and Constitution of the South African Practical Precision Rifle Federation (NPC). Defines the federation\'s structure, membership categories, meetings, elections, powers of Council and ExCo, finance, and disciplinary framework.',
                        'url' => route('legal.constitution'),
                        'badge' => ['label' => 'Foundational', 'tone' => 'sapphire'],
                        'last_updated' => $this->docMtime('docs/legal/constitution.md'),
                    ],
                    [
                        'title' => 'Terms & Conditions',
                        'subtitle' => 'Membership + platform use',
                        'description' => 'The contractual terms members accept at sign-up, covering platform use, liability cap, dispute resolution and code of conduct.',
                        'url' => route('legal.terms'),
                        'badge' => null,
                        'last_updated' => $this->docMtime('docs/legal/terms.md'),
                    ],
                    [
                        'title' => 'Privacy Policy',
                        'subtitle' => 'POPIA compliance',
                        'description' => 'How SAPRF collects, stores, uses and protects your personal information under POPIA, including your rights as a data subject.',
                        'url' => route('legal.privacy'),
                        'badge' => null,
                        'last_updated' => $this->docMtime('docs/legal/privacy.md'),
                    ],
                    [
                        'title' => 'Code of Conduct',
                        'subtitle' => 'Behaviour expected of members, athletes and officials',
                        'description' => 'The standard of behaviour SAPRF expects on and off the range — from every member, athlete, team, technical official, coach and administrator. Applies at every SAPRF-sanctioned event.',
                        'url' => route('legal.code-of-conduct'),
                        'badge' => null,
                        'last_updated' => $this->docMtime('docs/legal/code-of-conduct.md'),
                    ],
                    [
                        'title' => 'Conflict of Interest Policy',
                        'subtitle' => 'For all SAPRF Representatives',
                        'description' => 'How SAPRF Representatives (staff, volunteers, committee members, selectors, directors and officers) must handle real, perceived or potential conflicts of interest. Includes the standard declaration form.',
                        'url' => route('legal.conflict-of-interest'),
                        'badge' => null,
                        'last_updated' => $this->docMtime('docs/legal/conflict-of-interest.md'),
                    ],
                    [
                        'title' => 'Judicial Code',
                        'subtitle' => 'v1.0 · January 2019',
                        'description' => 'The judicial processes SAPPRF uses to fairly regulate disputes between members, contraventions of the Constitution / Rules & Regulations / Code of Conduct, and serious firearm safety infractions — covering mediation, arbitration, disciplinary, and appeal processes.',
                        'url' => route('legal.judicial-code'),
                        'badge' => ['label' => 'Current', 'tone' => 'emerald'],
                        'last_updated' => $this->docMtime('docs/legal/judicial-code.md'),
                    ],
                    [
                        'title' => 'Social Media Policy',
                        'subtitle' => 'v1.3 · May 2019',
                        'description' => 'The acceptable-use rules for social media by every SAPRF member, athlete, coach, official, employee and committee member — covering personal accounts, off-range conduct that is documented digitally, use of the SAPRF/PRS-SA identity, and the consequences of a breach under the Code of Conduct.',
                        'url' => route('legal.social-media-policy'),
                        'badge' => ['label' => 'Current', 'tone' => 'emerald'],
                        'last_updated' => $this->docMtime('docs/legal/social-media-policy.md'),
                    ],
                ],
            ],
        ];
    }

    private function docMtime(string $relPath): ?Carbon
    {
        $abs = base_path($relPath);
        return is_file($abs) ? Carbon::createFromTimestamp(filemtime($abs)) : null;
    }
}
