# Publishing SAPRF Documents

This is the maintainer guide for the `/documents` section of saprf.co.za. It covers how every published document (legal, selection, sport rules, FAQ) is authored, rendered, and surfaced on the public directory — and how to add a new one so it looks and behaves like the others.

## 1. Anatomy of a "cool format" document

Every long-form document on saprf.co.za goes through the same three-part pipeline:

1. **Source markdown** under `docs/{category}/{slug}.md` — the single source of truth. Verbatim from the federation's ratified text; **do not paraphrase**.
2. **Controller + route** that loads the file, runs it through `App\Support\MarkdownDocument::render()`, and hands the result to a Blade view.
3. **A view** that wraps the rendered HTML in the shared `<x-legal-document>` component — sticky ToC, clause gutter, reading-progress bar, print button, deep-link anchors, scroll-spy, Cmd+K section filter.

The rendered HTML is served at a short, stable URL (e.g. `/rules`, `/privacy`, `/selection/pr22-policy`). The document also gets a card on the public `/documents` index page.

If the federation has a signed PDF original, put it under `public/publications/<slug>.pdf` and expose it via the header's "Download original PDF" pill — **not** as the primary link on the `/documents` index. The HTML render is always the canonical entry point.

## 2. Files that make up an existing document

Take **SAPRF Divisions** as an example. It's just four files:

| File | Purpose |
|---|---|
| `docs/rules/divisions.md` | Source-of-truth markdown. |
| `app/Http/Controllers/RulesController.php` | Loads the MD, renders it, passes props to the view. |
| `resources/views/rules/divisions.blade.php` | Wraps the rendered HTML in `<x-legal-document>` with title, subtitle, version, effective-date, blurb. |
| `public/publications/saprf-divisions.pdf` | The federation's signed PDF, linked from the page header. |

Plus one entry in `DocumentsController::catalog()` so the directory page surfaces it, and one entry in `RulesController::RULEBOOKS` so the controller knows about it. That's the whole change surface.

## 3. Markdown conventions

The `MarkdownDocument` pipeline is opinionated. Follow these conventions or you'll get ugly output.

### 3.1. Document title & metadata

Every document opens with an H1, an optional bold subtitle line, and an italic version/date line. The H1 does **not** appear in the ToC — that's what the page header handles.

```markdown
# Code of Conduct

**South African Practical Precision Rifle Federation (NPC)**

*Updated: 29th January 2019 — v1.0*
```

### 3.2. Section headings

- `##` (H2) — top-level sections. Every H2 shows up in the sidebar ToC.
- `###` (H3) — sub-sections. Nested under the nearest H2 in the ToC.
- `####` (H4) — allowed, gets an id + anchor, but does **not** show in the ToC (keeps the sidebar scannable).

Use H2 for major chapters and H3 for sections **whose numbers appear in the PDF's table of contents**. Deeper subdivisions belong in the body as numbered clauses.

**Good:**

```markdown
## 3. Course Design
### 3.1. General Principles
### 3.2. Types of Courses
```

**Bad** (too many headings — the ToC becomes noise):

```markdown
## 3. Course Design
### 3.1. General Principles
#### 3.1.1. Every course...
#### 3.1.2. Stage briefings...
```

### 3.3. Numbered clauses

**Do not** hand-format clause numbers as `<span>`s. If a paragraph or list item starts with a multi-segment number (`N.N.`, `N.N.N.`, …), the pipeline lifts it into a hanging-indent gutter for you.

**Just write:**

```markdown
4.2.1. Every range must have a clearly-marked safety area.

4.2.2. The safety area must be visible to all competitors.

- 4.5.1.7.1. The National Council may…
```

**Do not write:**

```markdown
- **4.2.1.** Every range must…
```

The pipeline's clause splitter only fires on **two-or-more-segment** numbers, so bare `1.` at the start of a paragraph is treated as ordinary text — safe for chapter-level headings like `## 1. Interpretation`.

### 3.4. Lists

- Use `-` for bullets (not `*` or `•`).
- Nest with four spaces of indent.
- Ordinary bullets (no clause number) render as a plain `<ul>` with a stone-coloured marker.

### 3.5. Tables

Wide tables scroll horizontally on narrow screens automatically — the pipeline wraps every `<table>` in a `.table-scroll` container. Keep column headers short so tables don't overflow on desktop either.

```markdown
| Grade | Score threshold |
|---|---|
| Gold | 95 % and above |
| Silver | 85 % – < 95 % |
```

### 3.6. Emphasis

Use `**bold**` and `*italic*` sparingly — reserve them for terms of art, defined words, or headings that must be inline (e.g. "**Allowed modifications:**" before a bulleted list).

### 3.7. Do NOT include

- PDF page numbers, "Page 3 of 68" headers, running document titles.
- HTML tags — the pipeline is CommonMark with `html_input: escape`, so raw HTML is escaped, not rendered.
- Hand-crafted `<a name="…">` anchors — every H2/H3/H4 gets a slugified `id` automatically.

## 4. Adding a new document (step-by-step)

Suppose SAPRF ratifies a new document titled *"SAPRF Range Safety Protocol"*. Here's how to publish it.

### 4.1. Author the markdown

Save it as `docs/rules/range-safety.md`. Follow the conventions in §3.

### 4.2. (If it has a PDF) drop the PDF

`public/publications/saprf-range-safety.pdf`

Keep the filename kebab-case and stable — do **not** version-suffix it (like `-v2.pdf`), because future updates will just overwrite the file.

### 4.3. Wire up the controller

If the document logically fits an existing controller (legal → `LegalController`; sport rule → `RulesController`; selection policy → `PublicSelectionPolicyController`), add a method / entry there. Otherwise, mirror the shape of `RulesController` in a new controller.

For a new sport-rule doc, add an entry to `RulesController::RULEBOOKS` and a new method:

```php
private const RULEBOOKS = [
    'range-safety' => [
        'title' => 'SAPRF Range Safety Protocol',
        'kicker' => 'SAPRF · Sport Rules',
        'subtitle' => '…',
        'version' => '1.0',
        'effective_date' => '15 March 2026',
        'blurb' => '…',
        'md' => 'docs/rules/range-safety.md',
        'pdf' => 'publications/saprf-range-safety.pdf',
        'view' => 'rules.range-safety',
        'current_route' => 'rules-range-safety',
    ],
    // …existing rulebooks
];

public function rangeSafety(): View
{
    return $this->render('range-safety');
}
```

### 4.4. Register the route

In `routes/web.php`, add it next to the other rulebook routes:

```php
Route::get('/rules/range-safety', [\App\Http\Controllers\RulesController::class, 'rangeSafety'])->name('rules.range-safety');
```

Keep the URL short, kebab-case and stable — it will be linked from external sources. Sport rulebooks live under `/rules/` because the admin panel already owns `/divisions`; nesting keeps the paths short and collision-free.

### 4.5. Add the view

`resources/views/rules/range-safety.blade.php`:

```blade
<x-legal-document
    :title="$title"
    :kicker="$kicker"
    :subtitle="$subtitle"
    :version="$version"
    :effective-date="$effective_date"
    :status="['label' => 'Current', 'tone' => 'emerald']"
    :blurb="$blurb"
    :html="$html"
    :toc="$toc"
    :last-updated="$last_updated"
    :source-path="$source_path"
    :current-doc-route="$current_route"
>
    <x-slot:meta>
        @include('rules._pdf-download', ['pdf_url' => $pdf_url])
    </x-slot:meta>
</x-legal-document>
```

### 4.6. Surface it on the `/documents` index

In `DocumentsController::catalog()`, add an entry to the appropriate category (or create a new category if there isn't a good fit):

```php
[
    'title' => 'SAPRF Range Safety Protocol',
    'subtitle' => 'v1.0 · March 2026',
    'description' => 'Range officer responsibilities, safety area design, cease-fire protocol, and negligent-discharge handling for SAPRF-sanctioned matches.',
    'url' => route('rules.range-safety'),
    'badge' => ['label' => 'Current', 'tone' => 'emerald'],
    'last_updated' => $this->docMtime('docs/rules/range-safety.md'),
],
```

### 4.7. Add a test

Extend `tests/Feature/DocumentsIndexTest.php` and (for rulebooks) `tests/Feature/RulesPageTest.php` to assert:

- The new route returns 200.
- Something distinctive from the document body renders.
- The `/documents` index links to the new route.

Run:

```bash
./vendor/bin/pest tests/Feature/DocumentsIndexTest.php tests/Feature/RulesPageTest.php
```

### 4.8. Register it in the cross-document search corpus

The `/documents/search?q=…` endpoint scans a hand-curated corpus in `App\Support\DocumentSearch::CORPUS`. Add your document there so members can find its sections by keyword:

```php
[
    'doc_title' => 'SAPRF Range Safety Protocol',
    'kicker' => 'Sport Rules',                // pill label; matches /documents card tone
    'route' => 'rules.range-safety',          // named route from step 4.4
    // 'route_params' => ['series' => 'pr22'], // only if the route takes params
    'md_path' => 'docs/rules/range-safety.md',
],
```

Guidance:

- `kicker` should be one of `Sport Rules`, `Selection`, `Legal`, or `Help` — those are the four badge tones the search view knows how to style. Add a new one only if there's a whole new category, and then update the `$kickerTones` map in `resources/views/documents/search.blade.php`.
- If a listed file is missing on disk, the search silently skips it — so you can register future documents before publishing them.
- Search relevance is heading-weighted. Write H2s that name the topic (`## 6. National Provincial Series`, not `## 6. Series details`) so queries land on the right section.

### 4.9. Ship it

Commit only the files you added (source markdown, controller edits, view, route, catalog entry, search corpus entry, PDF, test). Push. On the server:

```bash
cd /opt/saprf && git pull origin main && docker compose build --no-cache app && docker compose up -d --force-recreate app scheduler queue
```

## 5. Design principles

A few rules that hold across the whole documents section:

- **The markdown is authoritative on-screen; the PDF is authoritative legally.** When they conflict, the PDF wins — say so in the page's `blurb`.
- **URLs are forever.** Once a URL is published, don't rename it. If a document is superseded, add a new URL and mark the old one Historical (not deleted) so external links keep working.
- **Metadata pills are for facts, not marketing.** The `Version`, `Effective`, and `Last updated` pills all render from real data. Don't fake them.
- **Do not create a directory named `public/documents/`.** The Laravel route `/documents` will 404 because Apache/nginx `try_files` refuses to rewrite an existing directory. Static assets live under `public/publications/`.
- **The Documents index is hand-curated.** `DocumentsController::catalog()` is a plain PHP array — federation controls the order, categories and descriptions. Don't auto-generate it from disk.

## 6. Cross-document search

Every document in the corpus is searchable at `/documents/search?q=…`. The service (`App\Support\DocumentSearch`) does everything on disk — no external index, no cron job, no cache to invalidate. On each request it:

1. Loads the markdown for every corpus entry that's present on disk.
2. Strips the H1 and splits the body on `##` boundaries. Content above the first H2 becomes a synthetic `Overview` section pointing at the document's own H1 anchor.
3. Tokenizes the query on ASCII word boundaries (min 2 chars) and scores each section: **+6** per token in the heading, **+2** per token in the doc title, **+1..+3** per token frequency in the body, plus **+8/+3** phrase-match bonuses for the full multi-word query. Zero-score sections are dropped.
4. Returns the top 25 hits with a ~240-char snippet centred on the first token match. Every hit deep-links to the section anchor (`/rules/pr22-rimfire#6-national-provincial-series`) — anchors match `MarkdownDocument`'s slug convention exactly, so the click always lands on the right heading.

The search view (`resources/views/documents/search.blade.php`) auto-escapes the query, then `App\Support\SearchHighlight` runs a regex over the pre-escaped text to wrap each token in `<mark>` — safe to output via `{!! !!}`.

To debug ranking, run the service directly in a `php artisan tinker` shell:

```php
foreach ((new App\Support\DocumentSearch)->search('your query here') as $hit) {
    printf("%-14s %-42s %-45s score=%d\n", $hit['kicker'], $hit['doc_title'], $hit['section_heading'], $hit['score']);
}
```

## 7. Where to look

- Shared markdown pipeline: `app/Support/MarkdownDocument.php`
- Shared Blade chrome: `resources/views/components/legal-document.blade.php`
- Directory page: `app/Http/Controllers/DocumentsController.php` + `resources/views/documents/index.blade.php`
- Cross-document search: `app/Support/DocumentSearch.php`, `app/Support/SearchHighlight.php`, `resources/views/documents/search.blade.php`, `resources/views/documents/_search-form.blade.php`
- Existing document controllers: `LegalController`, `RulesController`, `Selection/PublicSelectionPolicyController`
- CSS for the clause gutter, print styles, ToC scroll-spy: search `resources/css/` for `.legal-doc`.
