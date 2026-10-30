<x-legal-document
    :title="$title"
    kicker="SAPRF · Selection"
    :subtitle="$series_title . ' · ' . $season . ' Cycle'"
    :status="['label' => $status_label, 'tone' => $status_tone]"
    :effective-date="$season . ' cycle'"
    blurb="Reproduced verbatim from the SAPRF selection process document. In case of any conflict with the official SAPRF publication, the official publication is authoritative."
    :html="$html"
    :toc="$toc"
    :last-updated="$last_updated"
    :source-path="$source_path"
    :current-doc-route="'selection-policy-' . $series_key"
>
    @if(! empty($pdf_url) || ! empty($other_cycles) || ! empty($superseded_by))
        {{-- Series-switcher pills: land on a historical cycle and there's a
             one-click jump to the current one, so readers don't have to
             hunt through the Documents index to compare seasons. --}}
        <x-slot:meta>
            @include('rules._superseded')
            @include('rules._pdf-download', ['pdf_url' => $pdf_url ?? null])
            @foreach($other_cycles as $cycle)
                <a href="{{ $cycle['url'] }}"
                   class="inline-flex items-center gap-1 rounded-full bg-white text-stone-700 ring-1 ring-inset ring-stone-200 hover:bg-stone-50 hover:ring-stone-300 px-2.5 py-1 text-xs font-semibold transition">
                    {{ $cycle['label'] }}
                    @if($cycle['is_current'])
                        <span class="ml-0.5 inline-block rounded-full bg-emerald-100 text-emerald-800 px-1.5 py-px text-[10px] font-bold uppercase tracking-wide">Current</span>
                    @endif
                </a>
            @endforeach
        </x-slot:meta>
    @endif
</x-legal-document>
