<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
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
 * To publish a new document:
 *   1. Add its route + view in the appropriate controller (Legal, Selection…).
 *   2. Add an entry to $this->catalog() below.
 */
class DocumentsController extends Controller
{
    public function index(): View
    {
        return view('documents.index', [
            'categories' => $this->catalog(),
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
     *     }>
     * }>
     */
    private function catalog(): array
    {
        return [
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
