<x-layouts.app :title="'Edit team year — SAPRF'">
    <div class="max-w-4xl mx-auto px-4 sm:px-6 py-8">
        <a href="{{ route('team-years.index') }}" class="text-sm text-stone-500 hover:text-emerald-700 inline-flex items-center gap-1">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
            All team years
        </a>
        <h1 class="font-heading text-3xl font-bold text-stone-900 mt-3">
            {{ $team->year }} — {{ $team->championship_name }}
        </h1>
        <p class="text-sm text-stone-500 mt-1">
            <a href="{{ route('sa-team.year', ['year' => $team->year]) }}" target="_blank" class="hover:underline">View public page ↗</a>
        </p>

        @if(session('status'))
            <div class="mt-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 text-sm">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('team-years.update', ['teamYear' => $team->id]) }}"
              class="mt-6 bg-white border border-stone-200 rounded-2xl p-6">
            @csrf
            @method('PATCH')
            <div class="grid gap-5 sm:grid-cols-2">
                <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700 sm:col-span-2">
                    Championship name
                    <input type="text" name="championship_name" value="{{ old('championship_name', $team->championship_name) }}" required
                           class="min-h-[44px] px-3 rounded-lg border border-stone-300 text-base font-normal">
                </label>

                <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
                    Discipline
                    <select name="discipline"
                            class="min-h-[44px] px-3 rounded-lg border border-stone-300 text-base font-normal">
                        <option value="centrefire" @selected(old('discipline', $team->discipline) === 'centrefire')>Centrefire</option>
                        <option value="rimfire" @selected(old('discipline', $team->discipline) === 'rimfire')>Rimfire</option>
                    </select>
                </label>

                <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
                    Host country <span class="text-stone-400 font-normal">(ISO-3166 alpha-2)</span>
                    <input type="text" name="host_country" value="{{ old('host_country', $team->host_country) }}" maxlength="2" minlength="2"
                           class="min-h-[44px] px-3 rounded-lg border border-stone-300 text-base font-normal uppercase">
                </label>

                <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
                    Venue
                    <input type="text" name="venue" value="{{ old('venue', $team->venue) }}"
                           placeholder="e.g. Gravestone Precision Shooting"
                           class="min-h-[44px] px-3 rounded-lg border border-stone-300 text-base font-normal">
                </label>

                <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
                    City
                    <input type="text" name="city" value="{{ old('city', $team->city) }}"
                           placeholder="e.g. Lipan, Texas"
                           class="min-h-[44px] px-3 rounded-lg border border-stone-300 text-base font-normal">
                </label>

                <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
                    Starts on
                    <input type="date" name="starts_on" value="{{ old('starts_on', $team->starts_on?->format('Y-m-d')) }}"
                           class="min-h-[44px] px-3 rounded-lg border border-stone-300 text-base font-normal">
                </label>

                <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
                    Ends on
                    <input type="date" name="ends_on" value="{{ old('ends_on', $team->ends_on?->format('Y-m-d')) }}"
                           class="min-h-[44px] px-3 rounded-lg border border-stone-300 text-base font-normal">
                </label>

                <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700 sm:col-span-2">
                    Notes
                    <textarea name="notes" rows="3" maxlength="2000"
                              class="px-3 py-2 rounded-lg border border-stone-300 text-base font-normal">{{ old('notes', $team->notes) }}</textarea>
                </label>
            </div>

            {{-- Cover picker --}}
            <fieldset class="mt-8 border-t border-stone-100 pt-6">
                <legend class="font-semibold text-stone-900">Cover photo</legend>
                <p class="text-sm text-stone-500 mt-1">
                    Pick any photo from an album linked to this team, or from any Team SA album.
                </p>

                @if($pickableItems->isEmpty())
                    <p class="mt-4 text-sm text-amber-700 bg-amber-50 border border-amber-200 rounded-lg px-3 py-2">
                        No photos available. Create a <a href="{{ route('media.albums.create') }}" class="underline">Team SA album</a>
                        and upload some, or link an existing album to this team year.
                    </p>
                @else
                    <div class="mt-4 grid gap-3" style="grid-template-columns: repeat(auto-fill, minmax(min(180px, 100%), 1fr));">
                        <label class="relative block rounded-xl overflow-hidden border-2 bg-stone-100 aspect-[4/3] flex items-center justify-center text-stone-500 text-xs font-semibold cursor-pointer
                                      {{ $team->cover_media_item_id === null ? 'border-emerald-600 ring-2 ring-emerald-100' : 'border-transparent hover:border-stone-300' }}">
                            <input type="radio" name="cover_media_item_id" value="" @checked($team->cover_media_item_id === null) class="sr-only">
                            No cover
                        </label>
                        @foreach($pickableItems as $item)
                            <label class="relative block rounded-xl overflow-hidden border-2 cursor-pointer
                                          {{ $team->cover_media_item_id === $item->id ? 'border-emerald-600 ring-2 ring-emerald-100' : 'border-transparent hover:border-stone-300' }}">
                                <input type="radio" name="cover_media_item_id" value="{{ $item->id }}"
                                       @checked(old('cover_media_item_id', $team->cover_media_item_id) == $item->id)
                                       class="sr-only">
                                <div class="aspect-[4/3] bg-stone-100">
                                    <x-media-picture :item="$item" sizes="180px" class="w-full h-full"
                                                     imgClass="w-full h-full object-cover" />
                                </div>
                                <div class="absolute inset-x-0 bottom-0 bg-stone-900/70 text-white text-[11px] px-2 py-1 truncate">
                                    {{ $item->album?->title }}
                                </div>
                            </label>
                        @endforeach
                    </div>
                @endif
            </fieldset>

            <div class="mt-6 flex gap-3">
                <button type="submit" class="inline-flex items-center min-h-[44px] px-5 rounded-lg bg-emerald-700 text-white text-sm font-semibold hover:bg-emerald-800">
                    Save
                </button>
                <a href="{{ route('team-years.index') }}" class="inline-flex items-center min-h-[44px] px-5 rounded-lg border border-stone-300 text-stone-700 text-sm font-semibold hover:bg-stone-50">
                    Cancel
                </a>
            </div>
        </form>

        {{-- ─────────────────────────────────────────────────────────────
             Roster: appearances attached to this team year, with inline
             role/division/colours visible and per-row Edit links that
             return the admin back to this page after save.
             ─────────────────────────────────────────────────────────── --}}
        <section class="mt-10 bg-white border border-stone-200 rounded-2xl overflow-hidden">
            <header class="flex items-center justify-between gap-3 px-6 py-4 border-b border-stone-100">
                <div>
                    <h2 class="font-heading text-xl font-bold text-stone-900">Roster</h2>
                    <p class="text-xs text-stone-500 mt-0.5">
                        {{ $appearances->count() }} {{ Str::plural('appearance', $appearances->count()) }} on this team year.
                        Edit a row to change its role, division or Protea Colours flag.
                    </p>
                </div>
                <a href="{{ route('appearances.create') }}?championship_name={{ urlencode($team->championship_name) }}&year={{ $team->year }}&host_country={{ $team->host_country }}"
                   class="inline-flex items-center min-h-[40px] px-4 rounded-lg bg-emerald-700 text-white text-sm font-semibold hover:bg-emerald-800">
                    + Add shooter
                </a>
            </header>

            @if($appearances->isEmpty())
                <div class="px-6 py-10 text-center text-sm text-stone-500">
                    No appearances recorded for this team year yet.
                    Use "+ Add shooter" above to start the roster.
                </div>
            @else
                <table class="w-full text-sm">
                    <thead class="text-left text-xs uppercase tracking-wider text-stone-500 bg-stone-50 border-b border-stone-100">
                        <tr>
                            <th class="px-6 py-3 font-semibold">Shooter</th>
                            <th class="px-3 py-3 font-semibold">Role</th>
                            <th class="px-3 py-3 font-semibold">Division</th>
                            <th class="px-3 py-3 font-semibold">Colours</th>
                            <th class="px-3 py-3 font-semibold text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($appearances as $a)
                            <tr class="hover:bg-stone-50">
                                <td class="px-6 py-3">
                                    <div class="font-semibold text-stone-900">{{ $a->user?->name ?? 'Unknown' }}</div>
                                    @if($a->user?->membership?->saprf_number)
                                        <div class="text-xs text-stone-500 font-mono">{{ $a->user->membership->saprf_number }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-3">
                                    @php
                                        $roleLabel = \App\Models\NationalTeamAppearance::ROLES[$a->role ?? 'shooter'] ?? 'Shooter';
                                        $roleClass = match ($a->role) {
                                            'manager' => 'bg-amber-100 text-amber-800',
                                            'captain' => 'bg-emerald-100 text-emerald-800',
                                            'official' => 'bg-stone-200 text-stone-700',
                                            'reserve' => 'bg-sky-100 text-sky-800',
                                            default => 'bg-stone-100 text-stone-700',
                                        };
                                    @endphp
                                    <span class="inline-flex items-center px-2 py-0.5 rounded text-[11px] font-semibold uppercase tracking-wide {{ $roleClass }}">
                                        {{ $roleLabel }}
                                    </span>
                                </td>
                                <td class="px-3 py-3 text-stone-700">
                                    {{ $a->division?->name ?? $a->division_label ?? '—' }}
                                </td>
                                <td class="px-3 py-3">
                                    @if($a->awarded_colours)
                                        <span class="inline-flex items-center px-2 py-0.5 rounded bg-emerald-100 text-emerald-800 text-[11px] font-bold uppercase tracking-wide">
                                            Colours
                                        </span>
                                    @else
                                        <span class="text-stone-300 text-xs">—</span>
                                    @endif
                                </td>
                                <td class="px-3 py-3 text-right">
                                    <a href="{{ route('appearances.edit', ['appearance' => $a->id]) }}?back_to=team_year"
                                       class="text-emerald-700 font-semibold hover:underline text-sm">
                                        Edit
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </section>
    </div>
</x-layouts.app>
