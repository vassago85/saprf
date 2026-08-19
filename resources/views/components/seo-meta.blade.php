{{--
    Shared SEO + Open Graph tags for public (guest) pages.

    Default copy matches the homepage pitch. Per-page layouts pass a tighter
    description so search results and link previews are not all identical.
--}}
@props([
    'title' => 'SAPRF - South African Precision Rifle Federation',
    'description' => null,
    'robots' => 'index, follow',
    'canonical' => null,
    'image' => null,
    'type' => 'website',
])

@php
    $siteName = 'South African Precision Rifle Federation';
    $description = $description ?: 'The official SAPRF platform for PRS and PR22 — register for matches, track scores, and compete in national standings.';
    $canonical = $canonical ?: url()->current();
    $image = $image ?: asset('images/pwa/icon-512.png');
@endphp

<meta name="description" content="{{ $description }}">
<meta name="robots" content="{{ $robots }}">
<link rel="canonical" href="{{ $canonical }}">

<meta property="og:site_name" content="{{ $siteName }}">
<meta property="og:type" content="{{ $type }}">
<meta property="og:title" content="{{ $title }}">
<meta property="og:description" content="{{ $description }}">
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:image" content="{{ $image }}">
<meta property="og:locale" content="en_ZA">

<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="{{ $title }}">
<meta name="twitter:description" content="{{ $description }}">
<meta name="twitter:image" content="{{ $image }}">
