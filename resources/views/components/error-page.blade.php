@props([
    'status' => '404',
    'title' => 'Page not found',
    'message' => "We can't find the page you were looking for.",
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <x-google-tag />
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ $status }} · {{ $title }} — SAPRF</title>
    <link rel="icon" type="image/png" href="/favicon.png">

    <link rel="preconnect" href="https://fonts.bunny.net">
    <link href="https://fonts.bunny.net/css?family=inter:400,500,600,700,800|barlow-condensed:600,700,800&display=swap" rel="stylesheet" />

    @vite(['resources/css/app.css'])
</head>
<body class="force-light min-h-screen bg-stone-50 antialiased font-sans" style="color: #1c1917; color-scheme: light;">
    <main class="min-h-screen flex items-center justify-center px-4 py-16">
        <div class="w-full max-w-md text-center">
            <a href="{{ url('/') }}" class="inline-block mb-8">
                <img src="/saprf-logo-black-text.png" alt="SAPRF" class="h-10 w-auto mx-auto">
            </a>

            <div class="rounded-2xl border border-stone-200 bg-white shadow-sm p-8 space-y-6">
                <p class="font-heading text-6xl font-bold text-emerald-700 tracking-tight leading-none">{{ $status }}</p>

                <div>
                    <h1 class="font-heading text-2xl font-bold text-stone-900">{{ $title }}</h1>
                    <p class="mt-2 text-sm text-stone-500">{{ $message }}</p>
                </div>

                {{ $slot ?? '' }}

                <div class="flex flex-col sm:flex-row gap-3 justify-center">
                    <a href="{{ url('/') }}" class="px-5 py-2.5 rounded-xl bg-emerald-700 text-white text-sm font-semibold hover:bg-emerald-800 transition">
                        Return home
                    </a>
                    @auth
                        <a href="{{ url('/dashboard') }}" class="px-5 py-2.5 rounded-xl bg-stone-100 text-stone-700 text-sm font-semibold hover:bg-stone-200 transition">
                            Go to dashboard
                        </a>
                    @else
                        <a href="{{ url('/events') }}" class="px-5 py-2.5 rounded-xl bg-stone-100 text-stone-700 text-sm font-semibold hover:bg-stone-200 transition">
                            Browse events
                        </a>
                    @endauth
                </div>
            </div>

            <p class="mt-6 text-xs text-stone-400">South African Precision Rifle Federation</p>
        </div>
    </main>
</body>
</html>
