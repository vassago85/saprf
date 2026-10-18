@props(['section'])

@if ($section['expandable'])
    <ui-disclosure class="group/disclosure sidebar-nav-group" @if ($section['expanded']) open @endif data-flux-navlist-group>
        <button type="button" class="sidebar-section-toggle w-full h-10 lg:h-8 flex items-center group/disclosure-button mb-[2px] rounded-lg hover:bg-zinc-800/5 dark:hover:bg-white/[7%] text-zinc-500 hover:text-zinc-800 dark:text-white/80 dark:hover:text-white">
            <div class="ps-3 pe-4">
                <flux:icon.chevron-down class="size-3! hidden group-data-open/disclosure-button:block" />
                <flux:icon.chevron-right class="size-3! block group-data-open/disclosure-button:hidden rtl:rotate-180" />
            </div>

            <span class="sidebar-section-heading flex-1 text-[11px] font-semibold uppercase tracking-wider text-stone-600 leading-none text-start">{{ $section['heading'] }}</span>

            @if ($section['badge'] !== null)
                <flux:navlist.badge :color="$section['badge_color']" class="me-3 sidebar-nav-badge">{{ $section['badge'] }}</flux:navlist.badge>
            @endif
        </button>

        <div class="sidebar-section-items relative hidden data-open:block space-y-[2px] ps-7" @if ($section['expanded']) data-open @endif>
            <div class="sidebar-section-rail absolute inset-y-[3px] w-px bg-zinc-200 dark:bg-white/30 start-0 ms-4"></div>

            {{ $slot }}
        </div>
    </ui-disclosure>
@else
    <div class="block space-y-[2px]">
        <div class="sidebar-section-heading-row px-3 py-2 flex items-center gap-2">
            <div class="sidebar-section-heading flex-1 text-[11px] font-semibold uppercase tracking-wider text-stone-500 leading-none">{{ $section['heading'] }}</div>
            @if ($section['badge'] !== null)
                <flux:navlist.badge :color="$section['badge_color']" class="sidebar-nav-badge">{{ $section['badge'] }}</flux:navlist.badge>
            @endif
        </div>

        <div>
            {{ $slot }}
        </div>
    </div>
@endif
