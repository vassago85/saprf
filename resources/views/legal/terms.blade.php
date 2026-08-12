<x-legal-document
    title="Terms & Conditions"
    blurb="Reproduced verbatim from the SAPRF-supplied Terms & Conditions. In case of any conflict between this page and a signed copy of the SAPRF T&Cs, the signed copy prevails."
    :html="$html"
    :toc="$toc"
    :last-updated="$last_updated"
    :source-path="$source_path"
>
    <x-slot:meta>
        <span class="text-stone-300">·</span>
        <span>Liability cap <span class="font-mono text-stone-700">{{ $liability_cap }}</span></span>
        <span class="text-stone-300">·</span>
        <a href="{{ route('legal.privacy') }}" class="text-emerald-700 hover:text-emerald-800 underline underline-offset-2">Privacy Policy</a>
    </x-slot:meta>
</x-legal-document>
