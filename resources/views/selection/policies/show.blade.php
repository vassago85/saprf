<x-layouts.app :title="'Policy v'.$policy->version">
    <div class="space-y-6">
        <div>
            <div class="text-xs font-semibold uppercase tracking-widest text-stone-400">Policy · {{ $cycle->series }} {{ $cycle->season }}</div>
            <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">v{{ $policy->version }}</h1>
            <p class="mt-1 text-xs text-stone-500 font-mono">sha256: {{ $policy->source_hash }}</p>
            <p class="text-xs text-stone-500">Source: {{ $policy->source_path ?? '—' }} · Imported {{ $policy->imported_at?->format('Y-m-d H:i') }}</p>
        </div>
        <div class="rounded-xl border border-stone-200 bg-white p-5 shadow-sm">
            <pre class="max-h-[70vh] overflow-auto rounded-lg bg-stone-900 p-4 text-xs text-emerald-200">{{ json_encode($policy->spec_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
        </div>
    </div>
</x-layouts.app>
