@props([
    'title' => 'SAPRF',
    'current' => null,
    'sponsorPlacement' => null,
    'description' => null,
    'robots' => null,
    'canonical' => null,
    'image' => null,
    // When true, always render the public guest shell (with the public nav
    // + footer) even for authenticated users. Used by pages that need the
    // marketing/public look — e.g. /sa-team and /media — where embedding
    // the full authed app sidebar would be visually wrong. The public nav
    // itself already surfaces a "Dashboard" link for signed-in viewers.
    'forcePublic' => false,
])

@auth
@if(! $forcePublic)
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
@endif
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
