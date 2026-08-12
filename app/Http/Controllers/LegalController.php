<?php

namespace App\Http\Controllers;

use App\Models\MembershipFeeTier;
use Illuminate\Support\Str;
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

        [$html, $toc] = $this->renderWithToc($markdown);

        return view('legal.terms', [
            'html' => $html,
            'toc' => $toc,
            'source_path' => 'docs/legal/terms.md',
            'last_updated' => $this->lastUpdated($mdPath),
            'liability_cap' => $liabilityCap,
        ]);
    }

    public function privacy(): View
    {
        $mdPath = base_path('docs/legal/privacy.md');
        $markdown = is_file($mdPath) ? (string) file_get_contents($mdPath) : '';

        [$html, $toc] = $this->renderWithToc($markdown);

        return view('legal.privacy', [
            'html' => $html,
            'toc' => $toc,
            'source_path' => 'docs/legal/privacy.md',
            'last_updated' => $this->lastUpdated($mdPath),
        ]);
    }

    /**
     * Render markdown to HTML and extract an H2-level table of contents.
     * Each H2 element gets a unique, URL-safe id assigned so the TOC links
     * (#anchor) actually jump. Backing this into the controller (rather
     * than a CommonMark extension) keeps the dependency surface small.
     *
     * @return array{0: string, 1: array<int, array{id: string, text: string}>}
     */
    private function renderWithToc(string $markdown): array
    {
        $html = (new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]))->convert($markdown)->getContent();

        if (trim($html) === '') {
            return ['', []];
        }

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $toc = [];
        $usedIds = [];
        foreach ($doc->getElementsByTagName('h2') as $h2) {
            $text = trim((string) $h2->textContent);
            if ($text === '') {
                continue;
            }
            $baseId = Str::slug($text);
            if ($baseId === '') {
                continue;
            }
            $id = $baseId;
            $suffix = 2;
            while (isset($usedIds[$id])) {
                $id = $baseId.'-'.$suffix++;
            }
            $usedIds[$id] = true;
            $h2->setAttribute('id', $id);
            $toc[] = ['id' => $id, 'text' => $text];
        }

        // Re-serialize inner HTML of the wrapper div (the loadHTML wrapper).
        $wrapper = $doc->documentElement;
        $inner = '';
        foreach ($wrapper->childNodes as $child) {
            $inner .= $doc->saveHTML($child);
        }

        return [$inner, $toc];
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
