<x-legal-document
    title="Judicial Code"
    kicker="SAPRF · Governance"
    subtitle="Judicial processes for grievances, contraventions and serious firearm safety infractions"
    version="1.0"
    effective-date="29 January 2019"
    :status="['label' => 'Current', 'tone' => 'emerald']"
    blurb="Reproduced verbatim from the SAPPRF Judicial Code. In case of any conflict with the original signed publication, the PDF is authoritative."
    :html="$html"
    :toc="$toc"
    :last-updated="$last_updated"
    :source-path="$source_path"
>
    <x-slot:meta>
        @include('rules._pdf-download', ['pdf_url' => $pdf_url])
    </x-slot:meta>
</x-legal-document>
