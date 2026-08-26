<x-legal-document
    title="Social Media Policy"
    kicker="SAPRF · Governance"
    subtitle="Acceptable use of social media by SAPRF members, staff, athletes, coaches, officials and committee members"
    version="1.3"
    effective-date="17 May 2019"
    :status="['label' => 'Current', 'tone' => 'emerald']"
    blurb="Reproduced verbatim from the SAPPRF Social Media Policy. In case of any conflict with the original signed publication, the PDF is authoritative."
    :html="$html"
    :toc="$toc"
    :last-updated="$last_updated"
    :source-path="$source_path"
>
    <x-slot:meta>
        @include('rules._pdf-download', ['pdf_url' => $pdf_url])
    </x-slot:meta>
</x-legal-document>
