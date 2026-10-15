@props(['album', 'categories', 'teams', 'matches'])

<div class="grid gap-5 sm:grid-cols-2">
    <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700 sm:col-span-2">
        Title
        <input type="text" name="title" value="{{ old('title', $album->title) }}" required maxlength="200"
               class="min-h-[44px] px-3 rounded-lg border border-stone-300 focus:border-emerald-600 focus:ring-emerald-600 text-base font-normal">
    </label>

    <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
        Slug <span class="text-stone-400 font-normal">(optional — auto-generated)</span>
        <input type="text" name="slug" value="{{ old('slug', $album->slug) }}" maxlength="200"
               class="min-h-[44px] px-3 rounded-lg border border-stone-300 focus:border-emerald-600 focus:ring-emerald-600 text-base font-normal font-mono">
    </label>

    <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
        Category
        <select name="category" required
                class="min-h-[44px] px-3 rounded-lg border border-stone-300 focus:border-emerald-600 focus:ring-emerald-600 text-base font-normal">
            @foreach($categories as $key => $label)
                <option value="{{ $key }}" @selected(old('category', $album->category) === $key)>{{ $label }}</option>
            @endforeach
        </select>
    </label>

    <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
        Linked team year <span class="text-stone-400 font-normal">(optional)</span>
        <select name="national_team_id"
                class="min-h-[44px] px-3 rounded-lg border border-stone-300 focus:border-emerald-600 focus:ring-emerald-600 text-base font-normal">
            <option value="">— None —</option>
            @foreach($teams as $t)
                <option value="{{ $t->id }}" @selected(old('national_team_id', $album->national_team_id) == $t->id)>
                    {{ $t->year }} — {{ $t->championship_name }}
                </option>
            @endforeach
        </select>
    </label>

    <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
        Linked match <span class="text-stone-400 font-normal">(optional)</span>
        <select name="match_event_id"
                class="min-h-[44px] px-3 rounded-lg border border-stone-300 focus:border-emerald-600 focus:ring-emerald-600 text-base font-normal">
            <option value="">— None —</option>
            @foreach($matches as $m)
                <option value="{{ $m->id }}" @selected(old('match_event_id', $album->match_event_id) == $m->id)>
                    {{ $m->name }} ({{ $m->match_date?->format('j M Y') }})
                </option>
            @endforeach
        </select>
    </label>

    <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
        Event date <span class="text-stone-400 font-normal">(optional)</span>
        <input type="date" name="event_date" value="{{ old('event_date', $album->event_date?->format('Y-m-d')) }}"
               class="min-h-[44px] px-3 rounded-lg border border-stone-300 focus:border-emerald-600 focus:ring-emerald-600 text-base font-normal">
    </label>

    <label class="flex flex-col gap-1.5 text-sm font-semibold text-stone-700">
        Venue label <span class="text-stone-400 font-normal">(optional)</span>
        <input type="text" name="venue_label" value="{{ old('venue_label', $album->venue_label) }}" maxlength="200"
               placeholder="e.g. Lipan, Texas"
               class="min-h-[44px] px-3 rounded-lg border border-stone-300 focus:border-emerald-600 focus:ring-emerald-600 text-base font-normal">
    </label>

    <label class="flex items-center gap-3 sm:col-span-2 text-sm font-semibold text-stone-700">
        <input type="hidden" name="is_published" value="0">
        <input type="checkbox" name="is_published" value="1"
               @checked(old('is_published', $album->is_published))
               class="size-5 rounded border-stone-300 text-emerald-600 focus:ring-emerald-500">
        Published (visible on the public /media page)
    </label>
</div>

@error('title')<p class="mt-3 text-sm text-red-600">{{ $message }}</p>@enderror
@error('slug')<p class="mt-3 text-sm text-red-600">{{ $message }}</p>@enderror
@error('category')<p class="mt-3 text-sm text-red-600">{{ $message }}</p>@enderror
