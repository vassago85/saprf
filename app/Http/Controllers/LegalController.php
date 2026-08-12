<?php

namespace App\Http\Controllers;

use App\Models\MembershipFeeTier;
use Illuminate\View\View;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Serves the SAPRF legal documents (Terms & Conditions, Privacy Policy).
 *
 * Both documents are the verbatim text supplied by SAPRF's legal advisors —
 * the .md files under docs/legal/ are authoritative. The only substitution
 * the controller performs is the `{{LIABILITY_CAP}}` placeholder in the
 * T&Cs "Disclaimers and limitation of liability" clause, which is resolved
 * to the current highest annual membership fee so the cap stays consistent
 * with actual member spend without needing manual edits every time a fee
 * tier changes.
 */
class LegalController extends Controller
{
    public function terms(): View
    {
        $mdPath = base_path('docs/legal/terms.md');
        $markdown = is_file($mdPath) ? (string) file_get_contents($mdPath) : '';

        $liabilityCap = $this->currentLiabilityCap();
        $markdown = str_replace('{{LIABILITY_CAP}}', $liabilityCap, $markdown);

        return view('legal.terms', [
            'html' => $this->render($markdown),
            'source_path' => 'docs/legal/terms.md',
            'last_updated' => $this->lastUpdated($mdPath),
            'liability_cap' => $liabilityCap,
        ]);
    }

    public function privacy(): View
    {
        $mdPath = base_path('docs/legal/privacy.md');
        $markdown = is_file($mdPath) ? (string) file_get_contents($mdPath) : '';

        return view('legal.privacy', [
            'html' => $this->render($markdown),
            'source_path' => 'docs/legal/privacy.md',
            'last_updated' => $this->lastUpdated($mdPath),
        ]);
    }

    private function render(string $markdown): string
    {
        return (new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]))->convert($markdown)->getContent();
    }

    private function lastUpdated(string $mdPath): ?\Carbon\Carbon
    {
        return is_file($mdPath) ? \Carbon\Carbon::createFromTimestamp(filemtime($mdPath)) : null;
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
