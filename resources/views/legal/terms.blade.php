<x-legal-document
    title="Terms & Conditions"
    kicker="SAPRF · Legal"
    subtitle="In case of any conflict between this page and a signed copy of the SAPRF T&Cs, the signed copy prevails"
    blurb="Reproduced verbatim from the SAPRF-supplied Terms & Conditions."
    :html="$html"
    :toc="$toc"
    :last-updated="$last_updated"
    :source-path="$source_path"
>
    <x-slot:meta>
        <span class="inline-flex items-center gap-1.5 rounded-full bg-amber-50 text-amber-900 ring-1 ring-inset ring-amber-200 px-2.5 py-1 text-xs font-semibold">
            <svg class="size-3.5" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path d="M10.75 6.75a.75.75 0 0 0-1.5 0v4.5a.75.75 0 0 0 1.5 0v-4.5ZM10 15a1 1 0 1 0 0-2 1 1 0 0 0 0 2Z"/><path fill-rule="evenodd" d="M2 10a8 8 0 1 1 16 0 8 8 0 0 1-16 0Zm8-6.5a6.5 6.5 0 1 0 0 13 6.5 6.5 0 0 0 0-13Z" clip-rule="evenodd"/></svg>
            Liability cap <span class="font-mono">{{ $liability_cap }}</span>
        </span>
        <a href="{{ route('legal.privacy') }}"
           class="inline-flex items-center gap-1 rounded-full bg-white text-emerald-800 ring-1 ring-inset ring-emerald-200 px-2.5 py-1 text-xs font-semibold hover:bg-emerald-50 transition">
            Privacy Policy
            <svg class="size-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M4.25 5.5A.75.75 0 0 1 5 4.75h10a.75.75 0 0 1 .75.75v10a.75.75 0 0 1-1.5 0V7.31L5.53 16.03a.75.75 0 0 1-1.06-1.06L13.19 6.25H5A.75.75 0 0 1 4.25 5.5Z" clip-rule="evenodd"/></svg>
        </a>
    </x-slot:meta>
</x-legal-document>
