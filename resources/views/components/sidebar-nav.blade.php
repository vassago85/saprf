@php
    $navUser = auth()->user();
    $viewMode = $navUser?->effectiveViewMode() ?? \App\Support\SidebarNavigation::CONTEXT_SHOOTER;
    $sections = $navUser
        ? \App\Support\SidebarNavigation::sectionsFor($navUser, $viewMode)
        : [];
@endphp

<flux:navlist variant="outline">
    @foreach($sections as $section)
        @if($section['expandable'])
            <flux:navlist.group :heading="$section['heading']" expandable :expanded="$section['expanded']">
                @foreach($section['items'] as $item)
                    <x-sidebar-nav-item :item="$item" />
                @endforeach
            </flux:navlist.group>
        @else
            <flux:navlist.group :heading="$section['heading']">
                @foreach($section['items'] as $item)
                    <x-sidebar-nav-item :item="$item" />
                @endforeach
            </flux:navlist.group>
        @endif
    @endforeach
</flux:navlist>
