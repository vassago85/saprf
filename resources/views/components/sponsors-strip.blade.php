@props(['placement', 'class' => ''])

@php
    $sponsorService = app(\App\Services\SponsorService::class);
    $grouped = $sponsorService->getActiveByPlacement($placement);
@endphp

@if ($grouped->isNotEmpty())
    <div {{ $attributes->merge(['class' => 'py-10 ' . $class]) }}>
        <div class="max-w-6xl mx-auto px-6">
            <p class="text-xs uppercase tracking-wider text-stone-400 text-center mb-6">Supported By</p>

            @foreach ($grouped as $tierName => $sponsors)
                @php
                    $maxHeight = $sponsors->first()->tier->logo_max_height ?? 40;
                @endphp
                <div class="flex flex-wrap items-center justify-center gap-x-10 gap-y-4 @if (!$loop->last) mb-6 @endif">
                    @foreach ($sponsors as $sponsor)
                        @if ($sponsor->logoUrl())
                            @if ($sponsor->website_url)
                                <a href="{{ $sponsor->website_url }}" target="_blank" rel="noopener noreferrer"
                                    class="opacity-80 hover:opacity-100 transition" title="{{ $sponsor->name }}">
                                    <img src="{{ $sponsor->logoUrl() }}" alt="{{ $sponsor->name }}"
                                        style="max-height: {{ $maxHeight }}px" class="w-auto">
                                </a>
                            @else
                                <img src="{{ $sponsor->logoUrl() }}" alt="{{ $sponsor->name }}"
                                    style="max-height: {{ $maxHeight }}px" class="w-auto opacity-80" title="{{ $sponsor->name }}">
                            @endif
                        @endif
                    @endforeach
                </div>
            @endforeach
        </div>
    </div>
@endif
