@php
    $countdown = \App\Support\WorldsCountdown::make();
    $underway = $countdown->hasPassed();
@endphp

<section class="bg-stone-950 text-white" aria-labelledby="worlds-countdown-h">
    <div class="max-w-7xl mx-auto px-4 sm:px-6 py-8">
        <p id="worlds-countdown-h" class="text-[11px] font-semibold uppercase tracking-[0.16em] text-emerald-300">
            IPRF Centerfire World Championship · Mpumalanga
        </p>

        @if (! $countdown->isAnnounced())
            <p class="mt-3 font-heading text-2xl sm:text-3xl font-bold tracking-tight">August 2028</p>
            <p class="mt-2 text-sm text-stone-400">Final dates to be announced.</p>
        @elseif ($underway)
            <p class="mt-3 font-heading text-2xl sm:text-3xl font-bold tracking-tight">The championship is underway</p>
        @else
            <div
                class="mt-4 grid grid-cols-4 gap-2 sm:gap-4 max-w-xl"
                data-countdown-target="{{ $countdown->iso() }}"
                x-data="{
                    days: '—',
                    hours: '—',
                    minutes: '—',
                    seconds: '—',
                    tick() {
                        const target = new Date(this.$el.dataset.countdownTarget).getTime();
                        const diff = target - Date.now();
                        if (diff <= 0) {
                            this.days = '0';
                            this.hours = '00';
                            this.minutes = '00';
                            this.seconds = '00';
                            return;
                        }
                        const total = Math.floor(diff / 1000);
                        this.days = String(Math.floor(total / 86400));
                        this.hours = String(Math.floor((total % 86400) / 3600)).padStart(2, '0');
                        this.minutes = String(Math.floor((total % 3600) / 60)).padStart(2, '0');
                        this.seconds = String(total % 60).padStart(2, '0');
                    },
                }"
                x-init="tick(); setInterval(() => tick(), 1000)"
            >
                @foreach (['days' => 'Days', 'hours' => 'Hours', 'minutes' => 'Minutes', 'seconds' => 'Seconds'] as $key => $unit)
                    <div class="rounded-xl border border-white/10 bg-white/5 px-2 py-3 text-center">
                        <div class="font-heading text-2xl sm:text-4xl font-bold tabular-nums" x-text="{{ $key }}">—</div>
                        <div class="mt-1 text-[10px] sm:text-xs font-semibold uppercase tracking-wider text-stone-400">{{ $unit }}</div>
                    </div>
                @endforeach
            </div>
            <p class="mt-3 text-sm text-stone-400">{{ $countdown->label() }} · South African time</p>
        @endif
    </div>
</section>
