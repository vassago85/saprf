{{--
    Section 3 of 7 — Entries.
    Registration window + capacity + waitlist. The old Status select used
    to live in this card — it has been retired in favour of the stage bar
    + Next-action button in the chrome and the More menu's escape-hatch
    transitions. Field names and bindings are unchanged.
--}}
@php
    /** @var \App\Models\MatchEvent|null $match */
    $match = $match ?? null;

    $openValue = old('registration_open_date', $match?->registration_open_date?->format('Y-m-d'));
    $closeValue = old('registration_close_date', $match?->registration_close_date?->format('Y-m-d'));
    $maxValue = old('max_competitors', $match?->max_competitors);
    $waitlistValue = old('waitlist_enabled', $match?->waitlist_enabled);
@endphp
<section id="entries" class="scroll-mt-24 rounded-xl border border-stone-200 bg-white p-5">
    <header>
        <h3 class="text-sm font-semibold text-stone-900">Entries</h3>
        <p class="mt-0.5 text-xs text-stone-500">When registration is open and how many shooters can sign up.</p>
    </header>

    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <label for="registration_open_date" class="block text-xs font-medium text-stone-600 mb-1">Registration opens</label>
            <input type="date" name="registration_open_date" id="registration_open_date" value="{{ $openValue }}"
                   class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
            @error('registration_open_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="registration_close_date" class="block text-xs font-medium text-stone-600 mb-1">Registration closes</label>
            <input type="date" name="registration_close_date" id="registration_close_date" value="{{ $closeValue }}"
                   class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
            @error('registration_close_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="max_competitors" class="block text-xs font-medium text-stone-600 mb-1">Maximum competitors</label>
            <input type="number" name="max_competitors" id="max_competitors" min="1" max="999" value="{{ $maxValue }}"
                   placeholder="No limit"
                   class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
            <p class="mt-1 text-xs text-stone-400">Leave blank for unlimited entries.</p>
            @error('max_competitors') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div class="flex items-end">
            <label class="flex items-start gap-2">
                <input type="hidden" name="waitlist_enabled" value="0">
                <input type="checkbox" name="waitlist_enabled" value="1" @checked($waitlistValue)
                       class="mt-0.5 rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                <span>
                    <span class="block text-sm font-medium text-stone-800">Enable waitlist when full</span>
                    <span class="mt-0.5 block text-xs text-stone-500">Once capacity is reached, additional shooters can join a waitlist.</span>
                </span>
            </label>
        </div>
    </div>
</section>
