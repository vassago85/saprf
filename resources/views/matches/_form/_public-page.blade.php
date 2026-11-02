{{--
    Section 6 of 7 — Public page.
    Everything that renders on the public event page as a collapsible
    card: description, listing banner, poster, venue notes, schedule,
    attachments. The JSON fields stay as raw <textarea>s per the
    constraint — a richer builder is scheduled for a later iteration.

    (The squadding JSON lives on the Entries tab now, not here — the MD
    uses it after entries are in, so it fits better next to the list.)
--}}
@php
    /** @var \App\Models\MatchEvent|null $match */
    $match = $match ?? null;

    $descriptionValue = old('description', $match?->description);
    $venueDetailsValue = old('venue_details', $match?->venue_details);

    $scheduleRaw = old('schedule_json');
    if ($scheduleRaw === null && $match) {
        $scheduleRaw = is_array($match->schedule_json)
            ? json_encode($match->schedule_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : $match->schedule_json;
    }

    $attachmentsRaw = old('attachments_json');
    if ($attachmentsRaw === null && $match) {
        $attachmentsRaw = is_array($match->attachments_json)
            ? json_encode($match->attachments_json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
            : $match->attachments_json;
    }
@endphp
<section id="public-page" class="scroll-mt-24 rounded-xl border border-stone-200 bg-white p-5">
    <header>
        <h3 class="text-sm font-semibold text-stone-900">Public page</h3>
        <p class="mt-0.5 text-xs text-stone-500">Everything here renders on the public event page as a collapsible card — only when you fill it in.</p>
    </header>

    <div class="mt-4 space-y-5">
        <div>
            <label for="description" class="block text-xs font-medium text-stone-600 mb-1">Description</label>
            <textarea name="description" id="description" rows="3"
                      class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">{{ $descriptionValue }}</textarea>
            @error('description') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        {{-- Artwork: banner + poster. _media-fields is shared with the
             existing create flow and reads the current images off $match
             when it's present. --}}
        @include('matches._media-fields', ['match' => $match])

        <div>
            <label for="venue_details" class="block text-xs font-medium text-stone-600 mb-1">Venue notes <span class="text-stone-400 font-normal">(optional)</span></label>
            <textarea name="venue_details" id="venue_details" rows="3"
                      placeholder="Parking, bay access, overnight info, …"
                      class="block w-full rounded-lg border border-stone-300 text-sm focus:border-emerald-500 focus:ring-emerald-500">{{ $venueDetailsValue }}</textarea>
            @error('venue_details') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="schedule_json" class="block text-xs font-medium text-stone-600 mb-1">Schedule (JSON) <span class="text-stone-400 font-normal">(optional)</span></label>
            <textarea name="schedule_json" id="schedule_json" rows="4"
                      placeholder='[{"time":"07:30","label":"Registration & briefing"},{"time":"08:00","label":"Squad 1 to bay 1"}]'
                      class="block w-full rounded-lg border border-stone-300 font-mono text-xs focus:border-emerald-500 focus:ring-emerald-500">{{ $scheduleRaw }}</textarea>
            <p class="mt-1 text-xs text-stone-400">List of <code>{"time":"…","label":"…"}</code> rows. Leave blank to hide the schedule card.</p>
            @error('schedule_json') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>

        <div>
            <label for="attachments_json" class="block text-xs font-medium text-stone-600 mb-1">Attachments (JSON) <span class="text-stone-400 font-normal">(optional)</span></label>
            <textarea name="attachments_json" id="attachments_json" rows="4"
                      placeholder='[{"label":"Course of fire","url":"https://example.com/cof.pdf"}]'
                      class="block w-full rounded-lg border border-stone-300 font-mono text-xs focus:border-emerald-500 focus:ring-emerald-500">{{ $attachmentsRaw }}</textarea>
            <p class="mt-1 text-xs text-stone-400">List of <code>{"label":"…","url":"https://…"}</code> rows. URL is required per row.</p>
            @error('attachments_json') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
        </div>
    </div>
</section>
