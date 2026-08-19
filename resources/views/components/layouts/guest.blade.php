{{--
    `main` is false when the caller supplies its own <main> landmark — the public
    layout does, because its nav and footer must sit outside it.
--}}
@props([
    'main' => true,
    'title' => null,
    'description' => null,
    'robots' => null,
    'canonical' => null,
    'image' => null,
])

@php
    $pageTitle = $title ?? 'SAPRF - South African Precision Rifle Federation';
    $pageRobots = $robots ?? (request()->routeIs(
        'login',
        'register',
        'password.request',
        'password.reset',
        'invitation.accept',
        'verification.notice',
        'contact.thanks',
        'family.handover.accept',
    ) ? 'noindex, nofollow' : 'index, follow');
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <x-google-tag />
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $pageTitle }}</title>
    <link rel="icon" type="image/png" href="/favicon.png">

    <x-seo-meta
        :title="$pageTitle"
        :description="$description"
        :robots="$pageRobots"
        :canonical="$canonical"
        :image="$image"
    />

    <link rel="alternate" type="text/plain" title="LLM information" href="{{ url('/llms.txt') }}">

    <x-pwa-meta />

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800|barlow-condensed:600,700,800&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])

    @if (request()->routeIs('home'))
        <script type="application/ld+json">
            {!! json_encode([
                '@context' => 'https://schema.org',
                '@type' => 'SportsOrganization',
                'name' => 'South African Precision Rifle Federation',
                'alternateName' => 'SAPRF',
                'url' => url('/'),
                'logo' => asset('images/pwa/icon-512.png'),
                'description' => 'The official SAPRF platform for PRS and PR22 — register for matches, track scores, and compete in national standings.',
                'areaServed' => 'ZA',
                'sport' => 'Precision Rifle Shooting',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) !!}
        </script>
    @endif
</head>
<body class="force-light min-h-screen bg-white antialiased font-sans" style="color: #1c1917;">
    <x-skip-link />

    @if($main)
        <main id="main">{{ $slot }}</main>
    @else
        {{ $slot }}
    @endif

    @fluxScripts
</body>
</html>
