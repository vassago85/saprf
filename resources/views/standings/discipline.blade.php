@php
    // ----------------------------------------------------------------------
    // Discipline-specific public standings page.
    //
    // Routed from /standings/prs and /standings/pr22. The series arrives from
    // StandingController::publicDisciplineIndex via a route default (never a
    // query param), so bookmarks are canonical and the discipline switch is
    // a real link to the other URL.
    //
    // The Volt component below holds all filter / sort / search state via
    // Livewire's #[Url] attributes. The canonical URL for SEO strips every
    // filter so a search engine never indexes `?province_id=3&division_id=7`.
    // ----------------------------------------------------------------------

    $labels = [
        'PRS' => ['heading' => 'PRS standings', 'kicker' => 'Centrefire', 'nav' => 'standings'],
        'PR22' => ['heading' => 'PR22 standings', 'kicker' => 'Rimfire', 'nav' => 'standings'],
    ][$series];

    $canonicalRoute = $series === 'PRS' ? 'standings.prs' : 'standings.pr22';
@endphp

<x-layouts.public
    :title="$labels['heading'].' '.now()->year.' - SAPRF'"
    :description="'Official SAPRF '.$labels['heading'].' for the '.now()->year.' season — '.$labels['kicker'].', national and provincial rankings, movement arrows, and division leaders.'"
    :current="$labels['nav']"
    :canonical="route($canonicalRoute)"
    sponsor-placement="standings_pages"
>
    <livewire:standings.discipline-board :series="$series" />
</x-layouts.public>
