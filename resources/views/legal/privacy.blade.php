<x-legal-document
    title="Privacy Policy"
    kicker="SAPRF · Legal"
    subtitle="How SAPRF collects, stores, uses and protects your personal information under POPIA"
    blurb="Reproduced verbatim from the SAPRF-supplied Privacy Policy."
    :html="$html"
    :toc="$toc"
    :last-updated="$last_updated"
    :source-path="$source_path"
>
    <x-slot:meta>
        <a href="{{ route('legal.terms') }}"
           class="inline-flex items-center gap-1 rounded-full bg-white text-emerald-800 ring-1 ring-inset ring-emerald-200 px-2.5 py-1 text-xs font-semibold hover:bg-emerald-50 transition">
            Terms &amp; Conditions
            <svg class="size-3" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true"><path fill-rule="evenodd" d="M4.25 5.5A.75.75 0 0 1 5 4.75h10a.75.75 0 0 1 .75.75v10a.75.75 0 0 1-1.5 0V7.31L5.53 16.03a.75.75 0 0 1-1.06-1.06L13.19 6.25H5A.75.75 0 0 1 4.25 5.5Z" clip-rule="evenodd"/></svg>
        </a>
    </x-slot:meta>
</x-legal-document>
