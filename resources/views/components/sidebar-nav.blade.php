@php
    $navUser = auth()->user();
    $viewMode = $navUser?->effectiveViewMode() ?? \App\Support\SidebarNavigation::CONTEXT_SHOOTER;
    $sections = $navUser
        ? \App\Support\SidebarNavigation::sectionsFor($navUser, $viewMode)
        : [];
@endphp

<flux:navlist variant="outline">
    @foreach($sections as $section)
        <x-sidebar-nav-section :section="$section">
            @foreach($section['items'] as $item)
                <x-sidebar-nav-item :item="$item" />
            @endforeach
        </x-sidebar-nav-section>
    @endforeach
</flux:navlist>
