<x-layouts.app :title="'Edit appearance — ' . ($appearance->user?->name ?? 'Shooter')">
    <div class="max-w-2xl">
        <div class="mb-6">
            @php
                $backToTeamYear = request('back_to') === 'team_year' && $appearance->national_team_id;
                $backHref = $backToTeamYear
                    ? route('national-teams.edit', ['nationalTeam' => $appearance->national_team_id])
                    : route('national-team.index', ['year' => $appearance->year]);
                $backLabel = $backToTeamYear
                    ? 'Back to '.$appearance->year.' team year'
                    : 'Back to National Team';
            @endphp
            <a href="{{ $backHref }}" class="inline-flex items-center gap-1.5 text-sm text-stone-500 hover:text-emerald-700 transition mb-3">
                <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5 8.25 12l7.5-7.5" /></svg>
                {{ $backLabel }}
            </a>
            <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">
                Edit appearance
            </h1>
            <p class="mt-1 text-sm text-stone-500">
                {{ $appearance->user?->name }}
                @if($appearance->user?->membership?->saprf_number)
                    <span class="text-stone-400">·</span>
                    <span class="font-mono">{{ $appearance->user->membership->saprf_number }}</span>
                @endif
            </p>
        </div>

        <form action="{{ route('national-team.update', ['nationalTeam' => $appearance->id]) }}" method="POST" class="space-y-6 bg-white rounded-2xl border border-stone-200 shadow-sm p-6">
            @csrf
            @method('PATCH')
            @if($backToTeamYear)
                <input type="hidden" name="back_to" value="team_year">
            @endif

            {{-- Shooter is immutable: changing the shooter would be a different
                 appearance entirely, so if the admin picked the wrong person
                 they should delete this row and record a fresh one. --}}
            <div class="rounded-lg bg-stone-50 border border-stone-200 px-4 py-3">
                <p class="text-xs uppercase tracking-wider text-stone-500 font-semibold">Shooter</p>
                <p class="text-sm font-semibold text-stone-900 mt-0.5">{{ $appearance->user?->name ?? 'Unknown' }}</p>
                <p class="text-xs text-stone-500 mt-0.5">
                    To change the shooter, delete this row and record a new appearance for the correct person.
                </p>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="year" class="block text-sm font-medium text-stone-700 mb-1">Year</label>
                    <input type="number" id="year" name="year" min="1990" max="{{ now()->year + 1 }}"
                           value="{{ old('year', $appearance->year) }}" required
                           class="w-full rounded-lg border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 @error('year') border-red-300 @enderror">
                    @error('year')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="appeared_at" class="block text-sm font-medium text-stone-700 mb-1">Event date</label>
                    <input type="date" id="appeared_at" name="appeared_at"
                           value="{{ old('appeared_at', $appearance->appeared_at?->format('Y-m-d')) }}" required
                           class="w-full rounded-lg border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 @error('appeared_at') border-red-300 @enderror">
                    @error('appeared_at')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div>
                <label for="championship_name" class="block text-sm font-medium text-stone-700 mb-1">Championship / event name</label>
                <input type="text" id="championship_name" name="championship_name"
                       value="{{ old('championship_name', $appearance->championship_name) }}" required maxlength="255"
                       class="w-full rounded-lg border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 @error('championship_name') border-red-300 @enderror">
                <p class="mt-1 text-xs text-stone-500">If you change the year or name the appearance is re-attached to the matching team-year record (created automatically if it doesn't exist).</p>
                @error('championship_name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="host_country" class="block text-sm font-medium text-stone-700 mb-1">Host country</label>
                    <select id="host_country" name="host_country"
                            class="w-full rounded-lg border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 @error('host_country') border-red-300 @enderror">
                        <option value="">— Not specified —</option>
                        @foreach($countries as $code => $name)
                            <option value="{{ $code }}" @selected(old('host_country', $appearance->host_country) === $code)>{{ $name }}</option>
                        @endforeach
                    </select>
                    @error('host_country')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="placing" class="block text-sm font-medium text-stone-700 mb-1">
                        Placing <span class="text-stone-400 font-normal">(optional)</span>
                    </label>
                    <input type="number" id="placing" name="placing" min="1" max="999"
                           value="{{ old('placing', $appearance->placing) }}"
                           class="w-full rounded-lg border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 @error('placing') border-red-300 @enderror">
                    @error('placing')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div>
                    <label for="division_id" class="block text-sm font-medium text-stone-700 mb-1">
                        Division
                    </label>
                    <select id="division_id" name="division_id"
                            class="w-full rounded-lg border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 @error('division_id') border-red-300 @enderror">
                        <option value="">— Not specified —</option>
                        @foreach($divisions as $div)
                            <option value="{{ $div->id }}" @selected((int) old('division_id', $appearance->division_id) === $div->id)>{{ $div->name }}</option>
                        @endforeach
                    </select>
                    @error('division_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>

                <div>
                    <label for="division_label" class="block text-sm font-medium text-stone-700 mb-1">
                        Division label <span class="text-stone-400 font-normal">(legacy / free-text)</span>
                    </label>
                    <input type="text" id="division_label" name="division_label"
                           value="{{ old('division_label', $appearance->division_label) }}" maxlength="255"
                           class="w-full rounded-lg border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 @error('division_label') border-red-300 @enderror">
                    @error('division_label')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
                </div>
            </div>

            <div>
                <label for="role" class="block text-sm font-medium text-stone-700 mb-1">Team role</label>
                <select id="role" name="role"
                        class="w-full rounded-lg border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 @error('role') border-red-300 @enderror">
                    @foreach(\App\Models\NationalTeamAppearance::ROLES as $key => $label)
                        <option value="{{ $key }}" @selected(old('role', $appearance->role ?? 'shooter') === $key)>{{ $label }}</option>
                    @endforeach
                </select>
                <p class="mt-1 text-xs text-stone-500">
                    Promote a shooter to Captain by changing the role here. Managers never receive Protea Colours.
                </p>
                @error('role')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="selection_cycle_id" class="block text-sm font-medium text-stone-700 mb-1">
                    Selection cycle <span class="text-stone-400 font-normal">(optional)</span>
                </label>
                <select id="selection_cycle_id" name="selection_cycle_id"
                        class="w-full rounded-lg border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 @error('selection_cycle_id') border-red-300 @enderror">
                    <option value="">— No cycle (historical / pre-platform) —</option>
                    @foreach($cycles as $cycle)
                        <option value="{{ $cycle->id }}" @selected((int) old('selection_cycle_id', $appearance->selection_cycle_id) === $cycle->id)>
                            {{ $cycle->series }} · {{ $cycle->season }} · {{ $cycle->championship_name }}
                        </option>
                    @endforeach
                </select>
                @error('selection_cycle_id')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="rounded-xl border-2 border-emerald-200 bg-emerald-50/40 p-4">
                <label class="flex items-start gap-3 cursor-pointer">
                    <input type="checkbox" name="awarded_colours" value="1"
                           @checked(old('awarded_colours', $appearance->awarded_colours))
                           class="mt-0.5 size-5 rounded border-stone-300 text-emerald-700 focus:ring-emerald-500">
                    <div>
                        <p class="text-sm font-semibold text-stone-900">This appearance grants Protea Colours</p>
                        <p class="text-xs text-stone-600 mt-0.5">
                            A shooter has colours attached to <strong>one</strong> appearance. Ticking this box here will fail if the shooter already has colours on another row — untick that row first.
                        </p>
                    </div>
                </label>
                @error('awarded_colours')<p class="mt-2 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div>
                <label for="notes" class="block text-sm font-medium text-stone-700 mb-1">
                    Notes <span class="text-stone-400 font-normal">(internal, not shown publicly)</span>
                </label>
                <textarea id="notes" name="notes" rows="3" maxlength="2000"
                          class="w-full rounded-lg border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500 @error('notes') border-red-300 @enderror">{{ old('notes', $appearance->notes) }}</textarea>
                @error('notes')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
            </div>

            <div class="flex items-center gap-3 pt-2 border-t border-stone-100 pt-6">
                <button type="submit" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Save changes
                </button>
                <a href="{{ $backHref }}" class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>

                <div class="ml-auto">
                    {{-- Separate form so the DELETE doesn't carry the whole
                         edit form's bytes with it. --}}
                    <form method="POST" action="{{ route('national-team.destroy', ['nationalTeam' => $appearance->id]) }}"
                          onsubmit="return confirm('Delete this appearance for {{ addslashes($appearance->user?->name ?? 'this shooter') }}? This cannot be undone.')">
                        @csrf
                        @method('DELETE')
                        @if($backToTeamYear)
                            <input type="hidden" name="back_to" value="team_year">
                        @endif
                        <button type="submit" class="text-sm text-red-600 hover:text-red-800 font-semibold">
                            Delete appearance
                        </button>
                    </form>
                </div>
            </div>
        </form>
    </div>
</x-layouts.app>
