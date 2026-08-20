{{--
    Rifle spec cards shown at the top of the shooter profile.
    Renders one accordion per (Centerfire = PRS, Rimfire = PR22) rifle
    the shooter has flagged as visible on their profile.
--}}
@if($profileRifles->isNotEmpty())
    @php
        $centerfire = $profileRifles->firstWhere('primary_series', 'PRS');
        $rimfire = $profileRifles->firstWhere('primary_series', 'PR22');
    @endphp
    <div class="mb-6">
        <h2 class="font-heading text-xl font-bold text-stone-900 mb-3">Rifles</h2>
        <div class="space-y-4">
            @foreach([['label' => 'Centerfire', 'rifle' => $centerfire, 'series' => 'PRS'], ['label' => 'Rimfire', 'rifle' => $rimfire, 'series' => 'PR22']] as $group)
                @if($group['rifle'])
                    @php $rifle = $group['rifle']; $rows = $rifle->profileSpecRows(); @endphp
                    <div class="rounded-xl border border-stone-200 bg-white overflow-hidden" x-data="{ open: true }">
                        <div class="px-4 pt-3 text-xs font-semibold uppercase tracking-wider {{ $group['series'] === 'PRS' ? 'text-emerald-700' : 'text-sky-700' }}">{{ $group['label'] }}</div>
                        <button type="button" @click="open = ! open"
                            class="w-full flex items-center justify-between px-4 py-3 hover:bg-stone-50 transition text-left">
                            <span class="font-heading text-lg font-bold text-stone-900">{{ $rifle->displayName() }}</span>
                            <svg class="size-5 text-stone-400 transition-transform" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" :class="{ 'rotate-180': open }">
                                <path stroke-linecap="round" stroke-linejoin="round" d="m19.5 8.25-7.5 7.5-7.5-7.5"/>
                            </svg>
                        </button>
                        <div x-show="open" x-cloak class="border-t border-stone-100 divide-y divide-stone-100">
                            @forelse($rows as $row)
                                <div class="grid grid-cols-3 gap-3 px-4 py-2.5 text-sm">
                                    <dt class="font-semibold text-stone-700">{{ $row['label'] }}</dt>
                                    <dd class="col-span-2 text-stone-600">{{ $row['value'] }}</dd>
                                </div>
                            @empty
                                <p class="px-4 py-4 text-sm text-stone-400">No gear details shared yet.</p>
                            @endforelse
                        </div>
                    </div>
                @endif
            @endforeach
        </div>
    </div>
@endif
