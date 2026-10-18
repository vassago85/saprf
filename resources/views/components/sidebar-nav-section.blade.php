@props(['section'])

@if ($section['heading'] === null)
    {{-- Headingless section (Dashboard) renders inline, no toggle. --}}
    <div class="sidebar-nav-section mb-1">
        {{ $slot }}
    </div>
@elseif ($section['expandable'])
    <div class="sidebar-nav-section mb-1" data-section-key="{{ $section['key'] }}">
        <button type="button"
                @click="toggle('{{ $section['key'] }}')"
                :aria-expanded="isOpen('{{ $section['key'] }}').toString()"
                class="group flex w-full items-center gap-2 rounded-md px-3 py-1.5 text-start transition hover:bg-stone-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor"
                 class="size-3 shrink-0 text-stone-400 transition-transform duration-150"
                 :class="isOpen('{{ $section['key'] }}') ? 'rotate-90' : ''">
                <path stroke-linecap="round" stroke-linejoin="round" d="m8.25 4.5 7.5 7.5-7.5 7.5"/>
            </svg>
            <span class="sidebar-section-heading flex-1 truncate text-[11px] font-semibold uppercase tracking-wider text-stone-500 leading-none">
                {{ $section['heading'] }}
            </span>
            @if ($section['badge'] !== null)
                <span class="sidebar-nav-badge inline-flex min-w-[1.1rem] items-center justify-center rounded-full px-1.5 text-[10px] font-semibold leading-none
                             {{ $section['badge_color'] === 'amber' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }}
                             group-aria-expanded:[&]:hidden"
                      x-show="!isOpen('{{ $section['key'] }}')"
                      x-cloak>{{ $section['badge'] }}</span>
            @endif
        </button>

        <div x-show="isOpen('{{ $section['key'] }}')"
             x-collapse.duration.150ms
             class="mt-0.5 space-y-px">
            {{ $slot }}
        </div>
    </div>
@else
    {{-- Non-expandable section: fixed heading, always-open items. --}}
    <div class="sidebar-nav-section mb-1">
        <div class="flex items-center gap-2 px-3 py-1.5">
            <span class="sidebar-section-heading flex-1 truncate text-[11px] font-semibold uppercase tracking-wider text-stone-500 leading-none">
                {{ $section['heading'] }}
            </span>
            @if ($section['badge'] !== null)
                <span class="sidebar-nav-badge inline-flex min-w-[1.1rem] items-center justify-center rounded-full px-1.5 text-[10px] font-semibold leading-none
                             {{ $section['badge_color'] === 'amber' ? 'bg-amber-100 text-amber-800' : 'bg-emerald-100 text-emerald-800' }}">
                    {{ $section['badge'] }}
                </span>
            @endif
        </div>
        <div class="space-y-px">
            {{ $slot }}
        </div>
    </div>
@endif
