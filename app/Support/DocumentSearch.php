<?php

namespace App\Support;

use Illuminate\Support\Str;

/**
 * Cross-document full-text search over every markdown document SAPRF
 * publishes on the /documents section — legal docs, selection policies,
 * sport rulebooks, FAQ.
 *
 * The corpus is intentionally hand-curated (see self::CORPUS) so the
 * federation controls which documents are searchable and what titles /
 * kickers / URLs the search results carry. Adding a new searchable
 * document is a two-line change: append to CORPUS.
 *
 * How a query flows through the service:
 *
 *   1. Tokenize the query on non-word boundaries, lowercase, min 2 chars.
 *   2. Load each corpus MD and split it into H2 sections (one section =
 *      one H2 heading + everything until the next H2).
     *   3. Score each section by:
     *        - +6 for each query token that appears in the H2 heading
     *        - +2 for each query token that appears in the document title
     *        - +1..+3 for each token's frequency in the section body (capped)
     *        - +8 phrase-bonus if the full multi-word query is in the heading
     *        - +3 phrase-bonus if the full multi-word query is in the body
     *      Heading matches are weighted heavily so that a section literally
     *      named after the topic ("6. National Provincial Series") beats a
     *      long section that just happens to mention the terms in passing.
     *      Zero-score sections are dropped.
 *   4. Return top N hits sorted by score, each with a snippet drawn
 *      around the first token hit.
 *
 * Section anchor ids are generated with Str::slug() — the same function
 * MarkdownDocument::assignHeadingIds() uses — so the link
 * `/rules/pr22-rimfire#national-provincial-series` lands on the right
 * heading in the rendered page.
 */
class DocumentSearch
{
    /**
     * Every markdown document that should participate in search.
     *
     * `route` is a named route from routes/web.php; `route_params` may
     * be omitted for routes with no params. `kicker` is a short pill-
     * ready label shown above each hit — matches the tone of the doc's
     * own header kicker (e.g. "Sport Rules", "Selection", "Legal").
     *
     * If a listed markdown file is missing on disk (e.g. a policy hasn't
     * been published yet), that document is silently skipped so the
     * search endpoint never 500s on a half-deployed corpus.
     *
     * @var array<int, array{
     *   doc_title: string,
     *   kicker: string,
     *   route: string,
     *   route_params?: array<string, string>,
     *   md_path: string,
     * }>
     */
    private const CORPUS = [
        [
            'doc_title' => 'SAPRF Rules & Regulations',
            'kicker' => 'Sport Rules',
            'route' => 'rules.regulations',
            'md_path' => 'docs/rules/rules-and-regulations.md',
        ],
        [
            'doc_title' => 'SAPRF Divisions',
            'kicker' => 'Sport Rules',
            'route' => 'rules.divisions',
            'md_path' => 'docs/rules/divisions.md',
        ],
        [
            'doc_title' => 'PR22 Rimfire Series Rules',
            'kicker' => 'Sport Rules',
            'route' => 'rules.pr22-rimfire',
            'md_path' => 'docs/rules/pr22-rimfire-series.md',
        ],
        [
            'doc_title' => 'PR22 Team Selection (Rimfire) — 2027',
            'kicker' => 'Selection',
            'route' => 'selection.policy.public',
            'route_params' => ['series' => 'pr22'],
            'md_path' => 'docs/selection/pr22/2027/policy.md',
        ],
        [
            'doc_title' => 'PRS Team Selection (Centrefire) — 2026',
            'kicker' => 'Selection',
            'route' => 'selection.policy.public',
            'route_params' => ['series' => 'prs', 'season' => '2026'],
            'md_path' => 'docs/selection/prs/2026/policy.md',
        ],
        [
            'doc_title' => 'Constitution & Memorandum of Incorporation',
            'kicker' => 'Legal',
            'route' => 'legal.constitution',
            'md_path' => 'docs/legal/constitution.md',
        ],
        [
            'doc_title' => 'Terms & Conditions',
            'kicker' => 'Legal',
            'route' => 'legal.terms',
            'md_path' => 'docs/legal/terms.md',
        ],
        [
            'doc_title' => 'Privacy Policy',
            'kicker' => 'Legal',
            'route' => 'legal.privacy',
            'md_path' => 'docs/legal/privacy.md',
        ],
        [
            'doc_title' => 'Code of Conduct',
            'kicker' => 'Legal',
            'route' => 'legal.code-of-conduct',
            'md_path' => 'docs/legal/code-of-conduct.md',
        ],
        [
            'doc_title' => 'Conflict of Interest Policy',
            'kicker' => 'Legal',
            'route' => 'legal.conflict-of-interest',
            'md_path' => 'docs/legal/conflict-of-interest.md',
        ],
        [
            'doc_title' => 'Judicial Code',
            'kicker' => 'Legal',
            'route' => 'legal.judicial-code',
            'md_path' => 'docs/legal/judicial-code.md',
        ],
        [
            'doc_title' => 'Social Media Policy',
            'kicker' => 'Legal',
            'route' => 'legal.social-media-policy',
            'md_path' => 'docs/legal/social-media-policy.md',
        ],
        [
            'doc_title' => 'Frequently Asked Questions',
            'kicker' => 'Help',
            'route' => 'faq.index',
            'md_path' => 'docs/faq.md',
        ],
    ];

    /**
     * Return the top-scoring hits for a query.
     *
     * @return array<int, array{
     *   doc_title:string,
     *   kicker:string,
     *   doc_url:string,
     *   section_id:string,
     *   section_heading:string,
     *   snippet:string,
     *   tokens:array<int,string>,
     *   score:int
     * }>
     */
    public function search(string $query, int $limit = 25): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $tokens = $this->tokenize($query);
        if ($tokens === []) {
            return [];
        }

        $phrase = mb_strtolower($query);

        $hits = [];
        foreach (self::CORPUS as $doc) {
            $abs = base_path($doc['md_path']);
            if (! is_file($abs)) {
                continue;
            }
            $md = (string) file_get_contents($abs);

            $lowerDocTitle = mb_strtolower($doc['doc_title']);
            $sections = $this->splitSections($md, $doc['doc_title']);

            foreach ($sections as $section) {
                $score = $this->score($tokens, $phrase, $lowerDocTitle, $section);
                if ($score <= 0) {
                    continue;
                }

                $hits[] = [
                    'doc_title' => $doc['doc_title'],
                    'kicker' => $doc['kicker'],
                    'doc_url' => route($doc['route'], $doc['route_params'] ?? []),
                    'section_id' => $section['id'],
                    'section_heading' => $section['heading'],
                    'snippet' => $this->makeSnippet($section['body'], $tokens, $phrase),
                    'tokens' => $tokens,
                    'score' => $score,
                ];
            }
        }

        usort($hits, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
        return array_slice($hits, 0, $limit);
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        preg_match_all('/[a-z0-9]{2,}/i', mb_strtolower($text), $m);
        return array_values(array_unique($m[0]));
    }

    /**
     * Split a markdown document on H2 boundaries. Content above the
     * first H2 (subtitle line, version line, preamble) becomes a
     * synthetic first section whose heading is the doc title itself —
     * that way an "overview" match still surfaces something clickable.
     *
     * @return array<int, array{heading:string, id:string, body:string}>
     */
    private function splitSections(string $md, string $docTitle): array
    {
        // Strip a leading H1 — we don't want the doc title duplicated as
        // its own section heading; the synthetic preamble section
        // handles the pre-H2 content instead.
        $md = preg_replace('/^\s*#\s+.+\n+/', '', $md, 1) ?? $md;

        $lines = preg_split("/\r?\n/", $md);
        if ($lines === false) {
            return [];
        }

        $sections = [];
        $currentHeading = null;
        $currentBody = [];
        $preamble = [];

        foreach ($lines as $line) {
            if (preg_match('/^##\s+(.+?)\s*$/', $line, $m)) {
                if ($currentHeading !== null) {
                    $sections[] = $this->finalizeSection($currentHeading, $currentBody);
                }
                $currentHeading = trim($m[1]);
                $currentBody = [];
            } elseif ($currentHeading !== null) {
                $currentBody[] = $line;
            } else {
                $preamble[] = $line;
            }
        }
        if ($currentHeading !== null) {
            $sections[] = $this->finalizeSection($currentHeading, $currentBody);
        }

        // Any pre-first-H2 content (preamble, subtitle, etc.) → synthetic
        // "Overview" section so the doc still shows up if the query matches
        // just the intro paragraph.
        $preambleText = trim(implode("\n", $preamble));
        if ($preambleText !== '') {
            array_unshift($sections, [
                'heading' => 'Overview',
                'id' => Str::slug($docTitle),
                'body' => $preambleText,
            ]);
        }

        return $sections;
    }

    /**
     * @param  array<int, string>  $bodyLines
     * @return array{heading:string, id:string, body:string}
     */
    private function finalizeSection(string $heading, array $bodyLines): array
    {
        return [
            'heading' => $heading,
            'id' => Str::slug($heading),
            'body' => trim(implode("\n", $bodyLines)),
        ];
    }

    /**
     * @param  list<string>  $tokens
     * @param  array{heading:string, id:string, body:string}  $section
     */
    private function score(array $tokens, string $phrase, string $lowerDocTitle, array $section): int
    {
        $lowerHeading = mb_strtolower($section['heading']);
        $lowerBody = mb_strtolower($section['body']);

        $score = 0;
        foreach ($tokens as $t) {
            if (str_contains($lowerHeading, $t)) {
                $score += 6;
            }
            if (str_contains($lowerDocTitle, $t)) {
                $score += 2;
            }
            // Body term frequency, capped low. A long section shouldn't
            // outrank a section whose heading names the topic just
            // because it accumulates more incidental term mentions.
            $count = substr_count($lowerBody, $t);
            $score += min($count, 3);
        }

        // Phrase-match bonus — reward exact multi-word phrase hits so
        // that a query like "provincial requirements" boosts a section
        // that literally contains those two words next to each other,
        // over one that just happens to mention them separately.
        if (str_word_count($phrase) > 1) {
            if (str_contains($lowerHeading, $phrase)) {
                $score += 8;
            }
            if (str_contains($lowerBody, $phrase)) {
                $score += 3;
            }
        }

        return $score;
    }

    /**
     * Extract a ~240-char snippet from the section body centred on the
     * first token hit. Falls back to the section head if nothing matches
     * (which shouldn't happen given we already scored > 0, but be safe).
     *
     * @param  list<string>  $tokens
     */
    private function makeSnippet(string $body, array $tokens, string $phrase, int $before = 60, int $windowLen = 240): string
    {
        $flat = preg_replace('/\s+/', ' ', trim($body)) ?? '';
        if ($flat === '') {
            return '';
        }
        $lower = mb_strtolower($flat);

        // Prefer a hit on the full phrase; fall back to the earliest
        // token match. Multi-word phrase context is far more useful
        // than a random single-token hit deep in the body.
        $pos = str_word_count($phrase) > 1 ? strpos($lower, $phrase) : false;
        if ($pos === false) {
            $earliest = null;
            foreach ($tokens as $t) {
                $p = strpos($lower, $t);
                if ($p !== false && ($earliest === null || $p < $earliest)) {
                    $earliest = $p;
                }
            }
            $pos = $earliest ?? 0;
        }

        $start = max(0, $pos - $before);
        $snippet = substr($flat, $start, $windowLen);
        if ($start > 0) {
            $snippet = '… ' . ltrim($snippet);
        }
        if ($start + $windowLen < strlen($flat)) {
            $snippet = rtrim($snippet) . ' …';
        }
        return $snippet;
    }

    /**
     * Return the count of markdown files currently in the corpus and
     * present on disk. Handy for the search page's empty-state copy
     * ("Searching 11 documents…") and for tests to assert the corpus
     * is wired up.
     */
    public function corpusSize(): int
    {
        $n = 0;
        foreach (self::CORPUS as $doc) {
            if (is_file(base_path($doc['md_path']))) {
                $n++;
            }
        }
        return $n;
    }
}
