@php($c = $cycle ?? null)
<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-medium text-stone-700 mb-1">Series <span class="text-red-500">*</span></label>
        <select name="series" required class="block w-full rounded-lg border border-stone-300 text-sm">
            @foreach (['PR22', 'PRS'] as $opt)
                <option value="{{ $opt }}" @selected(old('series', $c?->series) === $opt)>{{ $opt }}</option>
            @endforeach
        </select>
    </div>
    <div>
        <label class="block text-sm font-medium text-stone-700 mb-1">Season <span class="text-red-500">*</span></label>
        <input name="season" type="text" required value="{{ old('season', $c?->season) }}" class="block w-full rounded-lg border border-stone-300 text-sm">
    </div>
    <div class="md:col-span-2">
        <label class="block text-sm font-medium text-stone-700 mb-1">Championship name <span class="text-red-500">*</span></label>
        <input name="championship_name" type="text" required value="{{ old('championship_name', $c?->championship_name) }}" class="block w-full rounded-lg border border-stone-300 text-sm">
    </div>
    <div>
        <label class="block text-sm font-medium text-stone-700 mb-1">Qualifying start <span class="text-red-500">*</span></label>
        <input name="qualifying_period_start" type="date" required value="{{ old('qualifying_period_start', optional($c?->qualifying_period_start)->format('Y-m-d')) }}" class="block w-full rounded-lg border border-stone-300 text-sm">
    </div>
    <div>
        <label class="block text-sm font-medium text-stone-700 mb-1">Qualifying end <span class="text-red-500">*</span></label>
        <input name="qualifying_period_end" type="date" required value="{{ old('qualifying_period_end', optional($c?->qualifying_period_end)->format('Y-m-d')) }}" class="block w-full rounded-lg border border-stone-300 text-sm">
    </div>
    <div>
        <label class="block text-sm font-medium text-stone-700 mb-1">Declaration deadline <span class="text-red-500">*</span></label>
        <input name="declaration_deadline" type="datetime-local" required value="{{ old('declaration_deadline', optional($c?->declaration_deadline)->format('Y-m-d\TH:i')) }}" class="block w-full rounded-lg border border-stone-300 text-sm">
    </div>
    <div>
        <label class="block text-sm font-medium text-stone-700 mb-1">Results freeze <span class="text-red-500">*</span></label>
        <input name="results_freeze" type="date" required value="{{ old('results_freeze', optional($c?->results_freeze)->format('Y-m-d')) }}" class="block w-full rounded-lg border border-stone-300 text-sm">
    </div>
    <div>
        <label class="block text-sm font-medium text-stone-700 mb-1">Panel lock date</label>
        <input name="panel_lock_date" type="date" value="{{ old('panel_lock_date', optional($c?->panel_lock_date)->format('Y-m-d')) }}" class="block w-full rounded-lg border border-stone-300 text-sm">
    </div>
    <div>
        <label class="block text-sm font-medium text-stone-700 mb-1">Deliberation start</label>
        <input name="deliberation_start" type="date" value="{{ old('deliberation_start', optional($c?->deliberation_start)->format('Y-m-d')) }}" class="block w-full rounded-lg border border-stone-300 text-sm">
    </div>
    <div>
        <label class="block text-sm font-medium text-stone-700 mb-1">Deliberation end</label>
        <input name="deliberation_end" type="date" value="{{ old('deliberation_end', optional($c?->deliberation_end)->format('Y-m-d')) }}" class="block w-full rounded-lg border border-stone-300 text-sm">
    </div>
    <div>
        <label class="block text-sm font-medium text-stone-700 mb-1">Publication date</label>
        <input name="publication_date" type="date" value="{{ old('publication_date', optional($c?->publication_date)->format('Y-m-d')) }}" class="block w-full rounded-lg border border-stone-300 text-sm">
    </div>
    @isset($c)
    <div>
        <label class="block text-sm font-medium text-stone-700 mb-1">Status</label>
        <select name="status" class="block w-full rounded-lg border border-stone-300 text-sm">
            @foreach (['draft', 'open', 'frozen', 'announced', 'closed'] as $s)
                <option value="{{ $s }}" @selected(old('status', $c->status) === $s)>{{ ucfirst($s) }}</option>
            @endforeach
        </select>
    </div>
    @endisset
</div>
@if ($errors->any())
    <div class="mt-4 rounded-lg border border-red-200 bg-red-50 p-3 text-sm text-red-800">
        <ul class="list-disc list-inside">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif
