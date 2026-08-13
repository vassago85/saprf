<?php

namespace App\Support;

use Illuminate\Support\Str;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Renders a verbatim markdown document to the polished HTML + nested ToC shape
 * that the shared <x-legal-document> Blade component expects.
 *
 * The pipeline is a series of DOM passes over CommonMark's output:
 *
 *   1. assignHeadingIds     — every H2/H3/H4 gets a stable slugified `id`.
 *   2. extractToc            — build an H2 → H3 outline for the sidebar.
 *   3. injectHeadingAnchors  — append a hover-revealed `#` link per heading.
 *   4. splitClauseNumbers    — lift leading `N.N.N.` numbers out of <p>/<li>
 *                              into a `.clause-num` gutter span.
 *   5. wrapTables            — put every <table> inside a horizontal-scroll
 *                              container so it doesn't blow out the reading
 *                              measure on narrow viewports.
 *
 * The service is shared by both LegalController (constitution, T&Cs, privacy,
 * code of conduct, conflict of interest) and PublicSelectionPolicyController
 * (PR22, PRS selection policies). Every legal / policy document rendered on
 * saprf.co.za flows through here — so any presentation upgrade is a one-file
 * change.
 */
class MarkdownDocument
{
    /**
     * @param  string  $markdown  raw markdown text
     * @param  array<string,string>  $replacements  optional token substitutions
     *         applied to the raw markdown before rendering (e.g. injecting
     *         the current liability cap into T&Cs).
     *
     * @return array{
     *   html: string,
     *   toc: array<int, array{
     *     id: string,
     *     text: string,
     *     children: array<int, array{id: string, text: string}>
     *   }>
     * }
     */
    public static function render(string $markdown, array $replacements = []): array
    {
        if ($replacements !== []) {
            $markdown = strtr($markdown, $replacements);
        }

        $html = (new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
        ]))->convert($markdown)->getContent();

        if (trim($html) === '') {
            return ['html' => '', 'toc' => []];
        }

        $doc = new \DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="UTF-8"?><div>'.$html.'</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        self::assignHeadingIds($doc);
        $toc = self::extractToc($doc);
        self::injectHeadingAnchors($doc);
        self::splitClauseNumbers($doc);
        self::wrapTables($doc);

        $wrapper = $doc->documentElement;
        $inner = '';
        foreach ($wrapper->childNodes as $child) {
            $inner .= $doc->saveHTML($child);
        }

        return ['html' => $inner, 'toc' => $toc];
    }

    /**
     * Assign a unique, URL-safe `id` to every H2/H3/H4. Collisions get a
     * `-2`, `-3` suffix so deep links stay stable across documents.
     */
    private static function assignHeadingIds(\DOMDocument $doc): void
    {
        $used = [];
        foreach (['h2', 'h3', 'h4'] as $tag) {
            foreach ($doc->getElementsByTagName($tag) as $node) {
                if ($node->hasAttribute('id')) {
                    $used[$node->getAttribute('id')] = true;
                    continue;
                }
                $text = trim((string) $node->textContent);
                if ($text === '') {
                    continue;
                }
                $base = Str::slug($text);
                if ($base === '') {
                    continue;
                }
                $id = $base;
                $suffix = 2;
                while (isset($used[$id])) {
                    $id = $base.'-'.$suffix++;
                }
                $used[$id] = true;
                $node->setAttribute('id', $id);
            }
        }
    }

    /**
     * Build a nested outline where each H3 attaches to the most recent H2.
     * H4+ are intentionally excluded — the sidebar has to stay scannable,
     * and a full outline of a 33-section constitution would defeat the purpose.
     *
     * @return array<int, array{id:string, text:string, children:array<int, array{id:string, text:string}>}>
     */
    private static function extractToc(\DOMDocument $doc): array
    {
        $toc = [];
        $currentH2Index = null;

        $xpath = new \DOMXPath($doc);
        $headings = $xpath->query('//h2 | //h3');
        if ($headings === false) {
            return [];
        }

        foreach ($headings as $node) {
            /** @var \DOMElement $node */
            $id = $node->getAttribute('id');
            $text = trim((string) $node->textContent);
            if ($id === '' || $text === '') {
                continue;
            }

            if (strtolower($node->nodeName) === 'h2') {
                $toc[] = ['id' => $id, 'text' => $text, 'children' => []];
                $currentH2Index = array_key_last($toc);
            } elseif ($currentH2Index !== null) {
                $toc[$currentH2Index]['children'][] = ['id' => $id, 'text' => $text];
            }
        }

        return $toc;
    }

    /**
     * Append a hover-revealed `#` anchor link to every H2/H3/H4. Clicking
     * pins the section's URL to the address bar — useful for cite-and-share.
     */
    private static function injectHeadingAnchors(\DOMDocument $doc): void
    {
        foreach (['h2', 'h3', 'h4'] as $tag) {
            foreach ($doc->getElementsByTagName($tag) as $node) {
                $id = $node->getAttribute('id');
                if ($id === '') {
                    continue;
                }
                $a = $doc->createElement('a', '#');
                $a->setAttribute('href', '#'.$id);
                $a->setAttribute('class', 'heading-anchor');
                $a->setAttribute('aria-label', 'Link to this section');
                $node->appendChild($a);
            }
        }
    }

    /**
     * Any <p> or <li> whose text begins with a multi-segment clause number
     * (`\d+(\.\d+)+.`) gets restructured so the number sits in a gutter:
     *
     *   Before: <p>14.5.1.7.1. The National Council may…</p>
     *   After:  <p class="clause" data-depth="5">
     *              <span class="clause-num">14.5.1.7.1.</span>
     *              <span class="clause-body">The National Council may…</span>
     *           </p>
     *
     * The two-span structure is what the .legal-doc CSS grid targets to hang
     * wrapped text under the first word of the body, not under the number.
     * `data-depth` steers depth-graded indentation and the nesting rule.
     *
     * Silently no-ops on documents that don't use N.N.N. numbering.
     */
    private static function splitClauseNumbers(\DOMDocument $doc): void
    {
        // Collect first, mutate after — snapshotting avoids the classic
        // "mutate a live NodeList while iterating" trap.
        $targets = [];
        foreach ($doc->getElementsByTagName('p') as $p) {
            $targets[] = $p;
        }
        foreach ($doc->getElementsByTagName('li') as $li) {
            $targets[] = $li;
        }

        foreach ($targets as $el) {
            self::splitClauseNumberOn($el);
        }
    }

    /**
     * If the first text-node child of $el starts with `N.N.N.` (at least two
     * segments so a bare `1.` doesn't accidentally match), lift the number
     * into a leading .clause-num span and wrap the remaining children in a
     * .clause-body span/div. Idempotent — already-processed elements bail out.
     */
    private static function splitClauseNumberOn(\DOMElement $el): void
    {
        if (str_contains((string) $el->getAttribute('class'), 'clause')) {
            return;
        }

        // Skip if the first child isn't text — leading <strong>, <a> etc.
        // never carry the clause number.
        $first = $el->firstChild;
        if (! $first instanceof \DOMText) {
            return;
        }

        if (! preg_match('/^\s*(\d+(?:\.\d+)+\.)(\s+)(.*)$/us', $first->nodeValue, $m)) {
            return;
        }

        $number = $m[1];
        $rest = $m[3];
        $depth = substr_count($number, '.'); // "14.5.1.7.1." → 5

        // Replace the leading text with the body remainder only. We keep the
        // same text node so subsequent children stay in place.
        $first->nodeValue = $rest;

        // Move every child of $el (including our just-trimmed text node) into
        // a new .clause-body wrapper, then prepend the .clause-num span.
        //
        // <p> may only contain phrasing content, so its body wrapper is a
        // <span>. <li> can contain flow content (including nested <ul>s for
        // further-nested clauses like 14.9.3.3.1.), so its wrapper is a
        // <div> to keep the resulting HTML valid.
        $bodyTag = strtolower($el->nodeName) === 'p' ? 'span' : 'div';
        $body = $el->ownerDocument->createElement($bodyTag);
        $body->setAttribute('class', 'clause-body');

        while ($el->firstChild) {
            $body->appendChild($el->firstChild);
        }

        $num = $el->ownerDocument->createElement('span');
        $num->setAttribute('class', 'clause-num');
        $num->appendChild($el->ownerDocument->createTextNode($number));

        $el->appendChild($num);
        $el->appendChild($body);

        $existing = trim((string) $el->getAttribute('class'));
        $el->setAttribute('class', trim($existing.' clause'));
        $el->setAttribute('data-depth', (string) $depth);
    }

    /**
     * Wrap every <table> in a <div class="table-scroll"> so wide tables
     * scroll horizontally on narrow viewports instead of forcing the whole
     * article wider than its measure. Idempotent.
     */
    private static function wrapTables(\DOMDocument $doc): void
    {
        $tables = [];
        foreach ($doc->getElementsByTagName('table') as $t) {
            $tables[] = $t;
        }
        foreach ($tables as $table) {
            $parent = $table->parentNode;
            if (! $parent) {
                continue;
            }
            if ($parent instanceof \DOMElement
                && str_contains((string) $parent->getAttribute('class'), 'table-scroll')) {
                continue;
            }
            $wrap = $table->ownerDocument->createElement('div');
            $wrap->setAttribute('class', 'table-scroll');
            $parent->replaceChild($wrap, $table);
            $wrap->appendChild($table);
        }
    }
}
