{{-- Match close-out wizard progress bar.
     Each cell renders done / current / locked styling, mirroring the stage
     bar in _chrome.blade.php so the two UI patterns stay visually familiar.
     --}}
<div class="mt-6 overflow-hidden rounded-xl border border-stone-200 bg-white">
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5">
        @foreach($wizard['steps'] as $index => $step)
            @php
                $cellClass = 'px-4 py-3 text-sm border-r border-stone-200 last:border-r-0';
                if ($step['current']) {
                    $cellClass .= ' bg-amber-50';
                } elseif ($step['done']) {
                    $cellClass .= ' bg-emerald-50/40';
                }
            @endphp
            <div class="{{ $cellClass }}">
                <div class="flex items-center gap-1.5 text-xs font-semibold uppercase tracking-wide
                    {{ $step['current'] ? 'text-amber-800' : ($step['done'] ? 'text-emerald-700' : 'text-stone-500') }}">
                    @if($step['done'])
                        <flux:icon.check class="size-3.5" />
                    @elseif($step['current'])
                        <span class="inline-flex size-4 items-center justify-center rounded-full bg-amber-500 text-[10px] font-bold text-white">
                            {{ $index + 1 }}
                        </span>
                    @else
                        <span class="inline-flex size-4 items-center justify-center rounded-full bg-stone-200 text-[10px] font-bold text-stone-500">
                            {{ $index + 1 }}
                        </span>
                    @endif
                    {{ $step['label'] }}
                </div>
            </div>
        @endforeach
    </div>
</div>
