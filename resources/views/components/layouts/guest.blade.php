{{--
    `main` is false when the caller supplies its own <main> landmark — the public
    layout does, because its nav and footer must sit outside it.
--}}
@props(['main' => true])
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $title ?? 'SAPRF - South African Precision Rifle Federation' }}</title>
    <link rel="icon" type="image/png" href="/favicon.png">

    <x-pwa-meta />

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800|barlow-condensed:600,700,800&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css', 'resources/js/app.js'])
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
