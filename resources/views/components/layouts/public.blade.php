@props([
    'title' => 'SAPRF',
    'current' => null,
    'sponsorPlacement' => null,
    'description' => null,
    'robots' => null,
    'canonical' => null,
    'image' => null,
])

@auth
<x-layouts.app :title="$title">
    {{ $slot }}
</x-layouts.app>
@else
<x-layouts.guest :main="false" :description="$description" :robots="$robots" :canonical="$canonical" :image="$image">
    <x-slot:title>{{ $title }}</x-slot:title>

    <x-public-nav :current="$current" />

    <main id="main">
        {{ $slot }}
    </main>

    @if($sponsorPlacement)
        <x-sponsors-strip :placement="$sponsorPlacement" class="border-t border-stone-200" />
    @endif
    <x-public-footer />
</x-layouts.guest>
@endauth
