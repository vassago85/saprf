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
>
    <x-slot:meta>
        @include('rules._pdf-download', ['pdf_url' => $pdf_url])
    </x-slot:meta>
</x-legal-document>
