<?php

namespace App\Http\Controllers;

use App\Models\MembershipFeeTier;
use Illuminate\Support\Str;
use Illuminate\View\View;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Serves the SAPRF legal + governance documents (Constitution/MOI, T&Cs,
 * Privacy, Code of Conduct, Conflict of Interest).
 *
 * All source content lives under `docs/legal/*.md` and is reproduced verbatim
 * — the .md files are authoritative. The only substitution the controller
 * performs is the `{{LIABILITY_CAP}}` placeholder in the T&Cs, resolved to
 * the current highest annual membership fee so the cap tracks fee changes
 * without needing manual edits.
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

    /**
     * Load a verbatim markdown document from disk and prepare the props every
     * legal page needs. Optional `$replacements` are applied to the raw
     * markdown before rendering — used for dynamic placeholders like the
     * T&Cs liability cap.
     *
     * @param  array<string,string>  $replacements
     * @return array{html:string, toc:array<int,array{id:string,text:string}>, source_path:string, last_updated:?\Carbon\Carbon}
     */
    private function loadMarkdownDocument(string $relPath, array $replacements = []): array
    {
        $absPath = base_path($relPath);
        $markdown = is_file($absPath) ? (string) file_get_contents($absPath) : '';

        if ($replacements !== []) {
            $markdown = strtr($markdown, $replacements);
        }

        [$html, $toc] = $this->renderWithToc($markdown);

        return [
            'html' => $html,
            'toc' => $toc,
            'source_path' => $relPath,
            'last_updated' => is_file($absPath)
                ? \Carbon\Carbon::createFromTimestamp(filemtime($absPath))
                : null,
        ];
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

        $wrapper = $doc->documentElement;
        $inner = '';
        foreach ($wrapper->childNodes as $child) {
            $inner .= $doc->saveHTML($child);
        }

        return [$inner, $toc];
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
