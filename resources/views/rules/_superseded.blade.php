{{-- Archived rulebook: points readers at the document that replaced it. --}}
@if(! empty($superseded_by))
    <a href="{{ $superseded_by['url'] }}"
       class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 text-emerald-800 ring-1 ring-inset ring-emerald-200 hover:bg-emerald-100 hover:ring-emerald-300 px-2.5 py-1 text-xs font-semibold transition"
       title="This document is archived. Read the current rules.">
        Current rules: {{ $superseded_by['title'] }}
    </a>
@endif
