<?php

namespace App\Http\Controllers;

use App\Models\MembershipFeeTier;
use Illuminate\View\View;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Serves the SAPRF legal documents (Terms & Conditions, Privacy Policy).
 *
 * The Terms document is the verbatim text supplied by SAPRF's legal
 * advisors — the .md file at docs/legal/terms.md is authoritative. The
 * only substitution the controller performs is the `{{LIABILITY_CAP}}`
 * placeholder in the "Disclaimers and limitation of liability" clause,
 * which is resolved to the current highest annual membership fee so the
 * cap stays consistent with actual member spend without needing manual
 * edits every time a fee tier changes.
 */
class LegalController extends Controller
{
    public function terms(): View
    {
        $mdPath = base_path('docs/legal/terms.md');
        $markdown = is_file($mdPath) ? (string) file_get_contents($mdPath) : '';

        $liabilityCap = $this->currentLiabilityCap();
        $markdown = str_replace('{{LIABILITY_CAP}}', $liabilityCap, $markdown);

        $html = (new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]))->convert($markdown)->getContent();

        return view('legal.terms', [
            'html' => $html,
            'source_path' => 'docs/legal/terms.md',
            'last_updated' => is_file($mdPath) ? \Carbon\Carbon::createFromTimestamp(filemtime($mdPath)) : null,
            'liability_cap' => $liabilityCap,
        ]);
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
