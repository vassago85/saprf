@props(['placement'])

@php
    $sponsorService = app(\App\Services\SponsorService::class);
    $grouped = $sponsorService->getActiveByPlacement($placement);
@endphp

@if ($grouped->isNotEmpty())
    <div class="px-3 py-3 border-t border-stone-100">
        <p class="text-[9px] uppercase tracking-wider text-stone-400 mb-2 px-1">Sponsors</p>
        <div class="flex flex-wrap items-center gap-3 px-1">
            @foreach ($grouped as $tierName => $sponsors)
                @foreach ($sponsors as $sponsor)
                    @if ($sponsor->logoUrl())
                        @if ($sponsor->website_url)
                            <a href="{{ $sponsor->website_url }}" target="_blank" rel="noopener noreferrer"
                                class="opacity-70 hover:opacity-100 transition" title="{{ $sponsor->name }}">
                                <img src="{{ $sponsor->logoUrl() }}" alt="{{ $sponsor->name }}"
                                    class="h-6 w-auto">
                            </a>
                        @else
                            <img src="{{ $sponsor->logoUrl() }}" alt="{{ $sponsor->name }}"
                                class="h-6 w-auto opacity-70" title="{{ $sponsor->name }}">
                        @endif
                    @endif
                @endforeach
            @endforeach
        </div>
    </div>
@endif
