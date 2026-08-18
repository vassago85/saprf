{{-- Shared meta-slot content for rulebook pages: a "Download original PDF"
     button. Rendered inside the <x-legal-document> header meta strip.
     Displayed only when a PDF URL is provided (all three rulebooks
     currently have one; if a future rulebook is markdown-only, just omit
     'pdf' from RulesController::RULEBOOKS and this slot will be empty). --}}
@if(! empty($pdf_url))
    <a href="{{ $pdf_url }}"
       target="_blank" rel="noopener"
       class="inline-flex items-center gap-1.5 rounded-full bg-blue-50 text-blue-800 ring-1 ring-inset ring-blue-200 hover:bg-blue-100 hover:ring-blue-300 px-2.5 py-1 text-xs font-semibold transition"
       title="Open the authoritative signed PDF in a new tab">
        <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
            <path fill-rule="evenodd" d="M10 2a.75.75 0 0 1 .75.75v8.69l2.72-2.72a.75.75 0 1 1 1.06 1.06l-4 4a.75.75 0 0 1-1.06 0l-4-4a.75.75 0 1 1 1.06-1.06l2.72 2.72V2.75A.75.75 0 0 1 10 2Z" clip-rule="evenodd"/>
            <path d="M3 14.5A1.5 1.5 0 0 1 4.5 13H6a.75.75 0 0 1 0 1.5H4.5v2h11v-2H14a.75.75 0 0 1 0-1.5h1.5A1.5 1.5 0 0 1 17 14.5v2A1.5 1.5 0 0 1 15.5 18h-11A1.5 1.5 0 0 1 3 16.5v-2Z"/>
        </svg>
        Download original PDF
    </a>
@endif
