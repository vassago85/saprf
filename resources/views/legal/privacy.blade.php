<x-legal-document
    title="Privacy Policy"
    blurb="Reproduced verbatim from the SAPRF-supplied Privacy Policy. Explains how SAPRF collects, stores, uses and protects your personal information under POPIA."
    :html="$html"
    :toc="$toc"
    :last-updated="$last_updated"
    :source-path="$source_path"
>
    <x-slot:meta>
        <span class="text-stone-300">·</span>
        <a href="{{ route('legal.terms') }}" class="text-emerald-700 hover:text-emerald-800 underline underline-offset-2">Terms &amp; Conditions</a>
    </x-slot:meta>
</x-legal-document>
