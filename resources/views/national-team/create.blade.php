<x-layouts.app :title="'Record National-Team Appearance - SAPRF'">
    <div class="max-w-2xl">
        <div class="mb-6">
            <a href="{{ route('national-team.index') }}" class="inline-flex items-center gap-1.5 text-sm text-stone-500 hover:text-emerald-700 transition mb-3">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                Back to National Team
            </a>
            <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Record National-Team Appearance</h1>
            <p class="mt-1 text-sm text-stone-500">One row per year the shooter shot for South Africa. Tick the Protea Colours box only for the shooter's <strong>first-ever</strong> SA appearance.</p>
        </div>

        <form action="{{ route('national-team.store') }}" method="POST" class="space-y-6 bg-white rounded-2xl border border-stone-200 shadow-sm p-6">
            @csrf

            <div>
                <label for="shooter_lookup" class="block text-sm font-medium text-stone-700 mb-1">
                    Shooter (SAPRF number or exact name)
                </label>
                <input type="text" id="shooter_lookup" name="shooter_lookup"
                       value="{{ old('shooter_lookup') }}" required autofocus
                       placeholder="e.g. 12345 or exact full name"
                       class="w-full rounded-lg border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 @error('shooter_lookup') border-red-300 @enderror">
                <p class="mt-1 text-xs text-stone-500">Best to use the SAPRF membership number — name matches must be exact.</p>
                @error('shooter_lookup')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="year" class="block text-sm font-medium text-stone-700 mb-1">Year</label>
                    <input type="number" id="year" name="year" min="1990" max="{{ now()->year + 1 }}"
                           value="{{ old('year', now()->year) }}" required
                           class="w-full rounded-lg border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 @error('year') border-red-300 @enderror">
                    @error('year')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="appeared_at" class="block text-sm font-medium text-stone-700 mb-1">Event date</label>
                    <input type="date" id="appeared_at" name="appeared_at"
                           value="{{ old('appeared_at', now()->toDateString()) }}" required
                           class="w-full rounded-lg border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 @error('appeared_at') border-red-300 @enderror">
                    <p class="mt-1 text-xs text-stone-500">Approximate championship date (used to order appearances chronologically).</p>
                    @error('appeared_at')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div>
                <label for="championship_name" class="block text-sm font-medium text-stone-700 mb-1">Championship / event name</label>
                <input type="text" id="championship_name" name="championship_name"
                       value="{{ old('championship_name') }}" required maxlength="255"
                       placeholder="e.g. IPRF PR22 World Championship"
                       class="w-full rounded-lg border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 @error('championship_name') border-red-300 @enderror">
                @error('championship_name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="host_country" class="block text-sm font-medium text-stone-700 mb-1">Host country</label>
                    <select id="host_country" name="host_country"
                            class="w-full rounded-lg border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 @error('host_country') border-red-300 @enderror">
                        <option value="">— Not specified —</option>
                        @foreach($countries as $code => $name)
                            <option value="{{ $code }}" @selected(old('host_country') === $code)>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('host_country')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="placing" class="block text-sm font-medium text-stone-700 mb-1">
                        Placing <span class="text-stone-400 font-normal">(optional)</span>
                    </label>
                    <input type="number" id="placing" name="placing" min="1" max="999"
                           value="{{ old('placing') }}"
                           placeholder="e.g. 12"
                           class="w-full rounded-lg border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 @error('placing') border-red-300 @enderror">
                    @error('placing')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="division_id" class="block text-sm font-medium text-stone-700 mb-1">
                        Division <span class="text-stone-400 font-normal">(optional)</span>
                    </label>
                    <select id="division_id" name="division_id"
                            class="w-full rounded-lg border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 @error('division_id') border-red-300 @enderror">
                        <option value="">— Not specified —</option>
                        @foreach($divisions as $div)
                            <option value="{{ $div->id }}" @selected((int) old('division_id') === $div->id)>{{ $div->name }}</option>
                        @endforeach
                    </select>
                    @error('division_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="division_label" class="block text-sm font-medium text-stone-700 mb-1">
                        Division label <span class="text-stone-400 font-normal">(legacy / free-text)</span>
                    </label>
                    <input type="text" id="division_label" name="division_label"
                           value="{{ old('division_label') }}" maxlength="255"
                           placeholder="e.g. Junior Open"
                           class="w-full rounded-lg border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 @error('division_label') border-red-300 @enderror">
                    <p class="mt-1 text-xs text-stone-500">Use only if the division isn't in the dropdown (e.g. historical divisions).</p>
                    @error('division_label')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div>
                <label for="selection_cycle_id" class="block text-sm font-medium text-stone-700 mb-1">
                    Selection cycle <span class="text-stone-400 font-normal">(optional)</span>
                </label>
                <select id="selection_cycle_id" name="selection_cycle_id"
                        class="w-full rounded-lg border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 @error('selection_cycle_id') border-red-300 @enderror">
                    <option value="">— No cycle (historical / pre-platform) —</option>
                    @foreach($cycles as $cycle)
                        <option value="{{ $cycle->id }}" @selected((int) old('selection_cycle_id') === $cycle->id)>
                            {{ $cycle->series }} · {{ $cycle->season }} · {{ $cycle->championship_name }}
                        </option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-stone-500">Link to a selection cycle if this appearance came out of the platform's team-selection process. Leave blank for historical entries.</p>
                @error('selection_cycle_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="rounded-xl border-2 border-emerald-200 bg-emerald-50/40 p-4">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="awarded_colours" value="1"
                           @checked(old('awarded_colours'))
                           class="mt-0.5 size-5 rounded border-stone-300 text-emerald-700 focus:ring-emerald-500">
                    <div>
                        <p class="text-sm font-semibold text-stone-900">This appearance grants Protea Colours</p>
                        <p class="text-xs text-stone-600 mt-0.5">Tick this ONLY for the shooter's <strong>first-ever</strong> SA representation. Every subsequent appearance is a national-team entry — the shooter keeps their existing colours, no new award. The system enforces one colours-awarding entry per shooter.</p>
                    </div>
                </label>
                @error('awarded_colours')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="notes" class="block text-sm font-medium text-stone-700 mb-1">
                    Notes <span class="text-stone-400 font-normal">(internal, not shown publicly)</span>
                </label>
                <textarea id="notes" name="notes" rows="3" maxlength="2000"
                          placeholder="e.g. source of record, ratification date..."
                          class="w-full rounded-lg border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 @error('notes') border-red-300 @enderror">{{ old('notes') }}</textarea>
                @error('notes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Record Appearance
                </button>
                <a href="{{ route('national-team.index') }}" class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts.app>
