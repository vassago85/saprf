{{--
    Sticky right-column navigator for the settings page. The seven
    section partials each own an `id="…"` anchor (basics, when-where,
    entries, fees, director-contact, public-page, scoring) so the links
    below scroll to each card. The nav also hosts the Save + Cancel
    buttons so they are always one click away.

    On mobile the whole page falls back to a horizontal scroll strip
    plus a sticky Save bar at the bottom of the viewport.

    Required locals:
      - $match (for the Cancel href back to the overview tab)
--}}
@php
    /** @var \App\Models\MatchEvent $match */
    $sections = [
        ['anchor' => 'basics', 'label' => 'Basics'],
        ['anchor' => 'when-where', 'label' => 'When & where'],
        ['anchor' => 'entries', 'label' => 'Entries'],
        ['anchor' => 'fees', 'label' => 'Fees'],
        ['anchor' => 'director-contact', 'label' => 'Director & contact'],
        ['anchor' => 'public-page', 'label' => 'Public page'],
        ['anchor' => 'scoring', 'label' => 'Scoring'],
    ];
@endphp

{{-- Desktop sticky side nav. Hidden on < lg. --}}
<aside class="hidden lg:block">
    <div class="sticky top-6 space-y-4">
        <nav aria-label="Settings sections" class="rounded-xl border border-stone-200 bg-white p-3">
            <ul class="space-y-0.5 text-sm">
                @foreach($sections as $section)
                    <li>
                        <a href="#{{ $section['anchor'] }}"
                           class="block rounded-md px-3 py-1.5 text-stone-600 hover:bg-stone-50 hover:text-emerald-700">
                            {{ $section['label'] }}
                        </a>
                    </li>
                @endforeach
            </ul>
        </nav>

        <div class="rounded-xl border border-stone-200 bg-white p-3">
            <div class="flex items-center gap-2">
                <flux:button type="submit" form="match-settings-form" variant="primary" class="flex-1">Save changes</flux:button>
            </div>
            <flux:button href="{{ route('matches.overview', $match) }}" wire:navigate variant="ghost" class="mt-2 w-full">Cancel</flux:button>
        </div>
    </div>
</aside>

{{-- Mobile: horizontal scroll strip of section links, right above the form.
     On tap it jumps to the section anchor. Hidden on >= lg. --}}
<nav aria-label="Settings sections (mobile)" class="-mx-4 flex gap-2 overflow-x-auto px-4 pb-2 lg:hidden">
    @foreach($sections as $section)
        <a href="#{{ $section['anchor'] }}"
           class="shrink-0 whitespace-nowrap rounded-full border border-stone-200 bg-white px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50">
            {{ $section['label'] }}
        </a>
    @endforeach
</nav>

{{-- Mobile: sticky Save bar pinned to the bottom of the viewport, hidden on >= lg. --}}
<div class="sticky bottom-0 -mx-4 flex items-center gap-2 border-t border-stone-200 bg-white/95 px-4 py-3 backdrop-blur lg:hidden">
    <flux:button type="submit" form="match-settings-form" variant="primary" class="flex-1">Save changes</flux:button>
    <flux:button href="{{ route('matches.overview', $match) }}" wire:navigate variant="ghost">Cancel</flux:button>
</div>
