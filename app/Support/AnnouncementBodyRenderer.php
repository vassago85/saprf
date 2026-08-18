<?php

namespace App\Support;

use Illuminate\Support\HtmlString;
use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Renders the free-text `body` field of an Announcement / MatchAnnouncement
 * into safe HTML for both the outgoing email and the on-portal view.
 *
 * Design goals:
 *
 *   1. Backward compatible with the previous `nl2br(e($body))` behaviour —
 *      admins who type plain paragraphs separated by single newlines still
 *      see line breaks in the rendered output. CommonMark treats a single
 *      newline as a "soft break" (a space in the paragraph flow), so we
 *      override that to emit `<br />` and mimic nl2br. Blank-line paragraph
 *      breaks still produce real <p> elements.
 *
 *   2. Safe by default. Any raw HTML in the body is escaped, not rendered,
 *      so an admin pasting `<script>alert(1)</script>` gets literal text on
 *      the wire. `allow_unsafe_links: false` blocks `javascript:` /
 *      `data:` / `vbscript:` URIs in markdown links, so we don't turn a
 *      typo'd announcement into an XSS vector.
 *
 *   3. Autolinks. Members regularly paste bare URLs into the composer;
 *      GFM autolinks turn https://saprf.co.za into a clickable link
 *      without the admin having to remember markdown syntax.
 *
 *   4. Non-destructive on plain text. `Hi team, the range is closed.` in →
 *      `<p>Hi team, the range is closed.</p>` out. No surprises.
 *
 * This class is deliberately separate from `MarkdownDocument`, which does
 * a much heavier document-shaped render (ToC extraction, clause-number
 * gutters, table-scroll wrappers). Announcement bodies are inline text —
 * they don't need any of that, and they'd be misrendered if they went
 * through it.
 */
class AnnouncementBodyRenderer
{
    /**
     * Render the raw markdown body to safe HTML ready for direct
     * interpolation into a Blade `{!! !!}` block or a `MailMessage::line()`
     * call via `HtmlString`.
     *
     * Empty / whitespace-only bodies return an empty HtmlString so callers
     * don't need to add a null-guard around them.
     */
    public static function toHtml(string $body): HtmlString
    {
        if (trim($body) === '') {
            return new HtmlString('');
        }

        $html = (new GithubFlavoredMarkdownConverter([
            'html_input' => 'escape',
            'allow_unsafe_links' => false,
            'renderer' => [
                'soft_break' => "<br />\n",
            ],
        ]))->convert($body)->getContent();

        return new HtmlString($html);
    }

    /**
     * Plain-text preview suitable for list cards / notification tickers.
     *
     * Renders the markdown to HTML, then strips every tag so `**Reminder**`
     * appears as "Reminder" in a preview card instead of the raw markdown
     * syntax. Newlines collapse to single spaces so the caller can safely
     * `Str::limit()` the result without landing mid-line.
     */
    public static function toPreview(string $body): string
    {
        if (trim($body) === '') {
            return '';
        }

        $plain = strip_tags((string) self::toHtml($body));
        $plain = preg_replace('/\s+/u', ' ', $plain) ?? $plain;

        return trim((string) html_entity_decode($plain, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
}
