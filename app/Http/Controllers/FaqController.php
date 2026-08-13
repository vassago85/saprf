<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;
use Illuminate\View\View;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Serves the public FAQ page.
 *
 * Source content lives at `docs/faq.md` — an H1 intro followed by one H2 per
 * question with the answer as the following block-level content. This
 * controller renders the markdown once and then splits it on H2 boundaries so
 * the view can render each question as an expandable accordion (rather than
 * dumping the whole thing as one long prose article).
 */
class FaqController extends Controller
{
    public function index(): View
    {
        $absPath = base_path('docs/faq.md');
        $markdown = is_file($absPath) ? (string) file_get_contents($absPath) : '';

        [$intro, $items] = $this->renderQuestions($markdown);

        return view('faq.index', [
            'intro' => $intro,
            'items' => $items,
            'source_path' => 'docs/faq.md',
            'last_updated' => is_file($absPath)
                ? \Carbon\Carbon::createFromTimestamp(filemtime($absPath))
                : null,
        ]);
    }

    /**
     * Render the FAQ markdown, then split the resulting HTML on <h2> boundaries.
     * Everything before the first <h2> becomes the intro; each <h2>'s text
     * becomes a question and the sibling nodes up to the next <h2> become the
     * answer HTML. The H1 title is discarded (the page already has its own).
     *
     * @return array{0: string, 1: array<int, array{question: string, anchor: string, html: string}>}
     */
    private function renderQuestions(string $markdown): array
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

        // Drop the H1 title — the Blade view renders its own page header.
        foreach (iterator_to_array($doc->getElementsByTagName('h1')) as $h1) {
            $h1->parentNode?->removeChild($h1);
        }

        $wrapper = $doc->documentElement;
        $intro = '';
        $items = [];
        $current = null;
        $usedIds = [];

        foreach (iterator_to_array($wrapper->childNodes) as $node) {
            if ($node->nodeType === XML_ELEMENT_NODE && strtolower($node->nodeName) === 'h2') {
                if ($current !== null) {
                    $items[] = $current;
                }
                $question = trim((string) $node->textContent);
                $baseId = Str::slug($question) ?: 'q';
                $anchor = $baseId;
                $suffix = 2;
                while (isset($usedIds[$anchor])) {
                    $anchor = $baseId.'-'.$suffix++;
                }
                $usedIds[$anchor] = true;
                $current = ['question' => $question, 'anchor' => $anchor, 'html' => ''];
                continue;
            }

            $rendered = $doc->saveHTML($node);
            if ($current === null) {
                $intro .= $rendered;
            } else {
                $current['html'] .= $rendered;
            }
        }

        if ($current !== null) {
            $items[] = $current;
        }

        return [trim($intro), $items];
    }
}
