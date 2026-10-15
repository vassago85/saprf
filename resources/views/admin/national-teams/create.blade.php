<x-layouts.app :title="'New team year — SAPRF'">
    <div class="max-w-2xl mx-auto px-4 sm:px-6 py-8">
        <a href="{{ route('team-years.index') }}" class="text-sm text-stone-500 hover:text-emerald-700 inline-flex items-center gap-1">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
            All team years
        </a>
        <h1 class="font-heading text-3xl font-bold text-stone-900 mt-3">New team year</h1>
        <p class="text-sm text-stone-500 mt-1">
            Create a team-year record for a future or historical IPRF championship.
            You'll add venue details, cover photo and the roster on the next screen.
        </p>

        <form method="POST" action="{{ route('team-years.store') }}" class="mt-6 bg-white border border-stone-200 rounded-2xl p-6">
            @csrf

            <div class="grid gap-5 sm:grid-cols-2">
                <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
                    Year
                    <input type="number" name="year" min="1990" max="{{ now()->year + 2 }}"
                           value="{{ old('year', $defaultYear) }}" required
                           class="min-h-[44px] px-3 rounded-lg border border-stone-300 text-base font-normal
                                  @error('year') border-red-300 @enderror">
                    @error('year')<span class="text-xs text-red-600 font-normal">{{ $message }}</span>@enderror
                </label>

                <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
                    Discipline
                    <select name="discipline" required
                            class="min-h-[44px] px-3 rounded-lg border border-stone-300 text-base font-normal
                                   @error('discipline') border-red-300 @enderror">
                        <option value="centrefire" @selected(old('discipline') === 'centrefire')>Centrefire</option>
                        <option value="rimfire" @selected(old('discipline') === 'rimfire')>Rimfire</option>
                    </select>
                    @error('discipline')<span class="text-xs text-red-600 font-normal">{{ $message }}</span>@enderror
                </label>

                <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700 sm:col-span-2">
                    Championship name
                    <input type="text" name="championship_name" value="{{ old('championship_name') }}" required maxlength="200"
                           placeholder="e.g. 2027 IPRF World Championship (Centrefire)"
                           class="min-h-[44px] px-3 rounded-lg border border-stone-300 text-base font-normal
                                  @error('championship_name') border-red-300 @enderror">
                    <span class="text-xs text-stone-500 font-normal">
                        A (year + championship name) pair must be unique.
                        Use "(Centrefire)" or "(Rimfire)" suffix when the same year hosts both.
                    </span>
                    @error('championship_name')<span class="text-xs text-red-600 font-normal">{{ $message }}</span>@enderror
                </label>

                <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
                    Host country <span class="text-stone-400 font-normal">(ISO-3166 alpha-2)</span>
                    <input type="text" name="host_country" value="{{ old('host_country') }}" maxlength="2" minlength="2"
                           placeholder="e.g. US"
                           class="min-h-[44px] px-3 rounded-lg border border-stone-300 text-base font-normal uppercase
                                  @error('host_country') border-red-300 @enderror">
                    @error('host_country')<span class="text-xs text-red-600 font-normal">{{ $message }}</span>@enderror
                </label>

                <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
                    City
                    <input type="text" name="city" value="{{ old('city') }}" maxlength="200"
                           placeholder="e.g. Lipan, Texas"
                           class="min-h-[44px] px-3 rounded-lg border border-stone-300 text-base font-normal">
                </label>

                <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700 sm:col-span-2">
                    Venue <span class="text-stone-400 font-normal">(optional)</span>
                    <input type="text" name="venue" value="{{ old('venue') }}" maxlength="200"
                           placeholder="e.g. Gravestone Precision Shooting"
                           class="min-h-[44px] px-3 rounded-lg border border-stone-300 text-base font-normal">
                </label>

                <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
                    Starts on <span class="text-stone-400 font-normal">(optional)</span>
                    <input type="date" name="starts_on" value="{{ old('starts_on') }}"
                           class="min-h-[44px] px-3 rounded-lg border border-stone-300 text-base font-normal">
                </label>

                <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
                    Ends on <span class="text-stone-400 font-normal">(optional)</span>
                    <input type="date" name="ends_on" value="{{ old('ends_on') }}"
                           class="min-h-[44px] px-3 rounded-lg border border-stone-300 text-base font-normal
                                  @error('ends_on') border-red-300 @enderror">
                    @error('ends_on')<span class="text-xs text-red-600 font-normal">{{ $message }}</span>@enderror
                </label>
            </div>

            <div class="mt-6 flex gap-3">
                <button type="submit" class="inline-flex items-center min-h-[44px] px-5 rounded-lg bg-emerald-700 text-white text-sm font-semibold hover:bg-emerald-800">
                    Create team year
                </button>
                <a href="{{ route('team-years.index') }}" class="inline-flex items-center min-h-[44px] px-5 rounded-lg border border-stone-300 text-stone-700 text-sm font-semibold hover:bg-stone-50">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</x-layouts.app>
