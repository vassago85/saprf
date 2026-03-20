<x-layouts.app :title="'Standings - SAPRF'">
    <div class="space-y-8">
        <div>
            <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Standings</h1>
            <p class="mt-1 text-sm text-stone-500">National and provincial rankings for the current season.</p>
        </div>

        <div x-data="{
            series: '{{ request('series', 'PRS') }}',
            level: '{{ request('level', 'national') }}',
            province: '{{ request('province_id', '') }}'
        }" class="space-y-6">

            {{-- Season + Series Controls --}}
            <div class="flex flex-wrap items-end gap-4">
                <form method="GET" action="{{ route('standings.index') }}" class="flex items-end gap-3">
                    <div>
                        <label class="block text-xs font-medium text-stone-500 mb-1">Season</label>
                        <select name="season" class="rounded-lg border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                            @foreach ($seasons as $s)
                                <option value="{{ $s }}" @selected($season == $s)>{{ $s }}</option>
                            @endforeach
                        </select>
                    </div>
                    <button type="submit" class="rounded-lg bg-stone-100 px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-200 transition">
                        Apply
                    </button>
                </form>
            </div>

            {{-- Series Toggle --}}
            <div class="flex gap-1 bg-stone-100 rounded-xl p-1 w-fit">
                <button @click="series = 'PRS'" :class="series === 'PRS' ? 'bg-emerald-700 text-white shadow-sm' : 'text-stone-600 hover:text-stone-900'" class="rounded-lg px-5 py-2 text-sm font-semibold transition">
                    PRS
                </button>
                <button @click="series = 'PR22'" :class="series === 'PR22' ? 'bg-sky-600 text-white shadow-sm' : 'text-stone-600 hover:text-stone-900'" class="rounded-lg px-5 py-2 text-sm font-semibold transition">
                    PR22
                </button>
            </div>

            {{-- Level Toggle --}}
            <div class="flex gap-1 bg-stone-100 rounded-xl p-1 w-fit">
                <button @click="level = 'national'" :class="level === 'national' ? 'bg-white text-stone-900 shadow-sm' : 'text-stone-500 hover:text-stone-700'" class="rounded-lg px-4 py-1.5 text-xs font-semibold transition">
                    National
                </button>
                <button @click="level = 'provincial'" :class="level === 'provincial' ? 'bg-white text-stone-900 shadow-sm' : 'text-stone-500 hover:text-stone-700'" class="rounded-lg px-4 py-1.5 text-xs font-semibold transition">
                    Provincial
                </button>
            </div>

            {{-- Province filter (shown only when provincial) --}}
            <div x-show="level === 'provincial'" x-cloak class="flex gap-3 items-end">
                <div>
                    <label class="block text-xs font-medium text-stone-500 mb-1">Province</label>
                    <select x-model="province" class="rounded-lg border-stone-300 text-sm py-2 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">All Provinces</option>
                        @foreach ($provinces as $p)
                            <option value="{{ $p->id }}">{{ $p->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Tables --}}
            <div x-show="series === 'PRS' && level === 'national'" x-cloak>
                @include('standings._table', ['standings' => $prsNational, 'showProvince' => true])
            </div>
            <div x-show="series === 'PRS' && level === 'provincial'" x-cloak>
                @include('standings._table', ['standings' => $prsProvincial, 'showProvince' => true])
            </div>
            <div x-show="series === 'PR22' && level === 'national'" x-cloak>
                @include('standings._table', ['standings' => $pr22National, 'showProvince' => true])
            </div>
            <div x-show="series === 'PR22' && level === 'provincial'" x-cloak>
                @include('standings._table', ['standings' => $pr22Provincial, 'showProvince' => true])
            </div>
        </div>

        <x-sponsors-strip placement="standings_pages" class="mt-8 border-t border-stone-200" />
    </div>
</x-layouts.app>
