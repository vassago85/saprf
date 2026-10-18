@props(['payload'])

@php
    /** @var \App\Support\Dashboard\DashboardPayload $payload */
@endphp

<div class="space-y-0">
    <x-dashboard.header
        :greeting="$payload->greeting"
        :title="$payload->title"
        :season="$payload->season"
        :show-season-select="$payload->showSeasonSelect"
        :actions="$payload->actions"
    />

    <x-dashboard.attention :items="$payload->attention" />

    <x-dashboard.kpi-grid :items="$payload->kpis" />

    @if ($payload->primary || $payload->secondary)
        <div class="mt-6 grid gap-6 xl:grid-cols-5">
            @if ($payload->primary)
                <x-dashboard.panel
                    class="xl:col-span-3"
                    :title="$payload->primary['title']"
                    :href="$payload->primary['href'] ?? null"
                    :href-label="$payload->primary['href_label'] ?? null"
                    :rows="$payload->primary['rows'] ?? []"
                />
            @endif
            @if ($payload->secondary)
                <x-dashboard.panel
                    class="xl:col-span-2"
                    :title="$payload->secondary['title']"
                    :href="$payload->secondary['href'] ?? null"
                    :href-label="$payload->secondary['href_label'] ?? null"
                    :rows="$payload->secondary['rows'] ?? []"
                />
            @endif
        </div>
    @endif

    @if ($payload->footer)
        <x-dashboard.pricing-strip
            :title="$payload->footer['title'] ?? 'Pricing & rules'"
            :href="$payload->footer['href'] ?? null"
            :href-label="$payload->footer['href_label'] ?? 'Edit pricing'"
            :items="$payload->footer['items'] ?? []"
        />
    @endif

    @if ($slot->isNotEmpty())
        <div class="mt-8 space-y-8">
            {{ $slot }}
        </div>
    @endif
</div>
