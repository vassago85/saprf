<?php

/**
 * Covers App\Support\AnnouncementBodyRenderer, the shared helper that
 * turns an admin-authored announcement body into safe HTML for both the
 * outgoing email and the on-portal view.
 *
 * The critical invariants we lock down here:
 *
 *   - Plain-text bodies keep working exactly as they did under the old
 *     nl2br(e($body)) pipeline (single newlines become <br>).
 *   - Basic markdown (bold, italic, lists, links, headings) renders.
 *   - Raw HTML is escaped, not executed — an admin pasting <script> gets
 *     literal text on the wire, not an XSS vector.
 *   - Dangerous URL schemes (javascript:, vbscript:, data:) are stripped
 *     from markdown links.
 *   - Bare URLs autolink (GitHub-flavoured markdown behaviour) so admins
 *     who paste https://saprf.co.za without wrapping it get a clickable
 *     link for free.
 */

use App\Support\AnnouncementBodyRenderer;

it('renders plain text unchanged as a paragraph', function () {
    $html = (string) AnnouncementBodyRenderer::toHtml('Hello team.');

    expect($html)->toContain('<p>Hello team.</p>');
});

it('converts single newlines to <br> so nl2br-style bodies still work', function () {
    $html = (string) AnnouncementBodyRenderer::toHtml("Line one.\nLine two.");

    expect($html)->toContain('Line one.')
        ->and($html)->toContain('<br')
        ->and($html)->toContain('Line two.');
});

it('renders blank-line separated paragraphs as separate <p> tags', function () {
    $html = (string) AnnouncementBodyRenderer::toHtml("First paragraph.\n\nSecond paragraph.");

    expect($html)->toContain('<p>First paragraph.</p>')
        ->and($html)->toContain('<p>Second paragraph.</p>');
});

it('renders bold and italic markdown', function () {
    $html = (string) AnnouncementBodyRenderer::toHtml('**bold** and *italic*.');

    expect($html)->toContain('<strong>bold</strong>')
        ->and($html)->toContain('<em>italic</em>');
});

it('renders bulleted lists', function () {
    $body = "Bring the following:\n\n- Rifle\n- Ammo\n- ID";
    $html = (string) AnnouncementBodyRenderer::toHtml($body);

    expect($html)->toContain('<ul>')
        ->and($html)->toContain('<li>Rifle</li>')
        ->and($html)->toContain('<li>Ammo</li>')
        ->and($html)->toContain('<li>ID</li>');
});

it('renders explicit markdown links', function () {
    $html = (string) AnnouncementBodyRenderer::toHtml('See [the site](https://saprf.co.za).');

    expect($html)->toContain('<a href="https://saprf.co.za"')
        ->and($html)->toContain('the site</a>');
});

it('autolinks bare URLs so admins do not have to wrap them', function () {
    $html = (string) AnnouncementBodyRenderer::toHtml('Visit https://saprf.co.za for details.');

    expect($html)->toContain('<a href="https://saprf.co.za"');
});

it('escapes raw HTML so <script> is neutralised', function () {
    $html = (string) AnnouncementBodyRenderer::toHtml('Hi <script>alert(1)</script> team.');

    expect($html)->not->toContain('<script>')
        ->and($html)->toContain('&lt;script&gt;');
});

it('blocks javascript: URLs in markdown links', function () {
    $html = (string) AnnouncementBodyRenderer::toHtml('[click](javascript:alert(1))');

    // league/commonmark strips the href entirely when allow_unsafe_links is off,
    // leaving the anchor with no href — no way to trigger the payload.
    expect($html)->not->toContain('href="javascript:');
});

it('renders headings', function () {
    $html = (string) AnnouncementBodyRenderer::toHtml("## Reminder\n\nRange is closed Saturday.");

    expect($html)->toContain('<h2>Reminder</h2>');
});

it('returns an empty HtmlString for whitespace-only bodies', function () {
    expect((string) AnnouncementBodyRenderer::toHtml(''))->toBe('')
        ->and((string) AnnouncementBodyRenderer::toHtml("   \n\t\n"))->toBe('');
});

it('toPreview strips markdown syntax and tags for list cards', function () {
    $preview = AnnouncementBodyRenderer::toPreview("**Reminder:** the range is closed.\n\nSee you Sunday.");

    expect($preview)->toBe('Reminder: the range is closed. See you Sunday.');
});

it('toPreview returns empty string for empty body', function () {
    expect(AnnouncementBodyRenderer::toPreview(''))->toBe('')
        ->and(AnnouncementBodyRenderer::toPreview("   \n"))->toBe('');
});
