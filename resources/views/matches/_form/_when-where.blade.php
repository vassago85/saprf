{{--
    Section 2 of 7 — When & where.
    Match-day date range + venue (via the shared address-book selector).
    Province sits below the venue fields so an admin picking a venue
    from the book sees the auto-populated province right where they
    expect it. On create the venue selector is empty; on settings it
    picks up `$match->venue_name` and locks the manual fields.
--}}
@php
    /** @var \App\Models\MatchEvent|null $match */
    $match = $match ?? null;
    $lockedByPaidEntries = $lockedByPaidEntries ?? false;
    $paidCount = $counts['paid'] ?? 0;

    $currentVenueName = old('venue_name', $match?->venue_name);
    $currentCity = old('city', $match?->city);
    $currentLocation = old('venue_location', $match?->venue_location);
    $currentProvinceId = old('province_id', $match?->province_id);
    $matchDateValue = old('match_date', $match?->match_date?->format('Y-m-d'));
    $matchEndDateValue = old('match_end_date', $match?->match_end_date?->format('Y-m-d'));
@endphp
<section id="when-where" class="scroll-mt-24 rounded-xl border border-stone-200 bg-white p-5">
    <header>
        <h3 class="text-sm font-semibold text-stone-900">When &amp; where</h3>
        <p class="mt-0.5 text-xs text-stone-500">Match day(s) and venue.</p>
    </header>

    <div class="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
            <label for="match_date" class="block text-xs font-medium text-stone-600 mb-1">
                Start date <span class="text-red-500">*</span>
                @if($lockedByPaidEntries)
                    @include('matches._form._locked-badge', ['paidCount' => $paidCount])
                @endif
            </label>
            <input type="date" name="match_date" id="match_date" value="{{ $matchDateValue }}" required
                   class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
            @error('match_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="match_end_date" class="block text-xs font-medium text-stone-600 mb-1">End date <span class="text-stone-400 font-normal">(multi-day)</span></label>
            <input type="date" name="match_end_date" id="match_end_date" value="{{ $matchEndDateValue }}"
                   class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500" />
            <p class="mt-1 text-xs text-stone-400">Leave blank for single-day matches.</p>
            @error('match_end_date') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        @include('matches._venue-selector', [
            'currentVenueName' => $currentVenueName,
            'currentCity' => $currentCity,
            'currentLocation' => $currentLocation,
            'currentProvinceId' => $currentProvinceId,
        ])

        {{-- Province sits below the venue so the auto-populated value from
             the address book is still visible in the viewport after the
             selector fills it in. --}}
        <div class="sm:col-span-2 sm:max-w-sm">
            <label for="province_id" class="block text-xs font-medium text-stone-600 mb-1">Province</label>
            <select name="province_id" id="province_id"
                    class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">
                <option value="">Select province…</option>
                @foreach ($provinces as $province)
                    <option value="{{ $province->id }}" @selected((string) $currentProvinceId === (string) $province->id)>{{ $province->name }}</option>
                @endforeach
            </select>
            @error('province_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>
</section>
