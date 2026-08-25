<?php

namespace App\Http\Controllers;

use App\Models\MembershipFeeTier;
use App\Support\MarkdownDocument;
use Illuminate\View\View;

/**
 * Serves the SAPRF legal + governance documents (Constitution/MOI, T&Cs,
 * Privacy, Code of Conduct, Conflict of Interest).
 *
 * All source content lives under `docs/legal/*.md` and is reproduced verbatim
 * — the .md files are authoritative. The only substitution the controller
 * performs is the `{{LIABILITY_CAP}}` placeholder in the T&Cs, resolved to
 * the current highest annual membership fee so the cap tracks fee changes
 * without needing manual edits.
 *
 * The heavy lifting (heading ids, ToC extraction, clause-number gutter,
 * table wrapping) is delegated to App\Support\MarkdownDocument so that the
 * PublicSelectionPolicyController produces identically-structured output
 * for the shared <x-legal-document> Blade component.
 */
class LegalController extends Controller
{
    public function terms(): View
    {
        $liabilityCap = $this->currentLiabilityCap();
        $props = $this->loadMarkdownDocument('docs/legal/terms.md', [
            '{{LIABILITY_CAP}}' => $liabilityCap,
        ]);

        return view('legal.terms', $props + ['liability_cap' => $liabilityCap]);
    }

    public function privacy(): View
    {
        return view('legal.privacy', $this->loadMarkdownDocument('docs/legal/privacy.md'));
    }

    public function codeOfConduct(): View
    {
        return view('legal.code-of-conduct', $this->loadMarkdownDocument('docs/legal/code-of-conduct.md'));
    }

    public function conflictOfInterest(): View
    {
        return view('legal.conflict-of-interest', $this->loadMarkdownDocument('docs/legal/conflict-of-interest.md'));
    }

    public function constitution(): View
    {
        return view('legal.constitution', $this->loadMarkdownDocument('docs/legal/constitution.md'));
    }

    public function judicialCode(): View
    {
        // The Judicial Code is unusual among the legal docs in that it also
        // has a signed PDF original we surface via the "Download original
        // PDF" pill in the page header. All other legal docs are markdown-
        // only for now.
        return view('legal.judicial-code', $this->loadMarkdownDocument('docs/legal/judicial-code.md') + [
            'pdf_url' => asset('publications/saprf-judicial-code.pdf'),
        ]);
    }

    /**
     * Load a verbatim markdown document from disk and prepare the props every
     * legal page needs.
     *
     * @param  array<string,string>  $replacements
     * @return array{
     *   html:string,
     *   toc:array<int,array{id:string,text:string,children:array<int,array{id:string,text:string}>}>,
     *   source_path:string,
     *   last_updated:?\Carbon\Carbon
     * }
     */
    private function loadMarkdownDocument(string $relPath, array $replacements = []): array
    {
        $absPath = base_path($relPath);
        $markdown = is_file($absPath) ? (string) file_get_contents($absPath) : '';

        $rendered = MarkdownDocument::render($markdown, $replacements);

        return [
            'html' => $rendered['html'],
            'toc' => $rendered['toc'],
            'source_path' => $relPath,
            'last_updated' => is_file($absPath)
                ? \Carbon\Carbon::createFromTimestamp(filemtime($absPath))
                : null,
        ];
    }

    /**
     * The T&Cs cap our contractual liability at a fixed rand amount. To
     * avoid it drifting out of sync with fee changes we drive it from the
     * highest active membership fee tier — that's the most a member could
     * ever have paid us for their annual subscription. Falls back to
     * "R 1,000" if fee tiers haven't been seeded yet (fresh install / tests).
     */
    private function currentLiabilityCap(): string
    {
        try {
            $topFee = MembershipFeeTier::query()
                ->active()
                ->orderByDesc('price')
                ->value('price');

            if ($topFee !== null) {
                return 'R ' . number_format((float) $topFee, 0, '.', ',');
            }
        } catch (\Throwable) {
            // Fee tiers table not migrated yet (fresh install, tests).
        }

        return 'R 1,000';
    }
}
