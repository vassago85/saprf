<x-layouts.app :title="'Provincial Committees - SAPRF'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Provincial Committees</h1>
                <p class="mt-1 text-sm text-stone-500">Overview of committee appointments across all provinces.</p>
            </div>
            <a href="{{ route('provincial-committees.create') }}"
                class="inline-flex items-center gap-2 rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                Appoint Member
            </a>
        </div>

        <div class="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            @foreach ($provinces as $province)
                <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
                    <div class="border-b border-stone-100 bg-stone-50 px-5 py-3">
                        <h2 class="font-heading text-lg font-bold text-stone-900">{{ $province->name }}</h2>
                    </div>
                    <div class="p-5">
                        @if ($province->committeeMembers->isNotEmpty())
                            <ul class="space-y-2.5">
                                @foreach ($province->committeeMembers as $member)
                                    <li class="flex items-center gap-2">
                                        @switch($member->position)
                                            @case('chair')
                                                <span class="inline-flex items-center rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-semibold uppercase text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Chair</span>
                                                @break
                                            @case('vice_chair')
                                                <span class="inline-flex items-center rounded-full bg-sky-50 px-2 py-0.5 text-[10px] font-semibold uppercase text-sky-700 ring-1 ring-inset ring-sky-600/20">Vice Chair</span>
                                                @break
                                            @case('treasurer')
                                                <span class="inline-flex items-center rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-semibold uppercase text-amber-700 ring-1 ring-inset ring-amber-600/20">Treasurer</span>
                                                @break
                                            @case('secretary')
                                                <span class="inline-flex items-center rounded-full bg-stone-100 px-2 py-0.5 text-[10px] font-semibold uppercase text-stone-600 ring-1 ring-inset ring-stone-500/20">Secretary</span>
                                                @break
                                            @default
                                                <span class="inline-flex items-center rounded-full bg-stone-50 px-2 py-0.5 text-[10px] font-semibold uppercase text-stone-500 ring-1 ring-inset ring-stone-400/20">Member</span>
                                        @endswitch
                                        <span class="text-sm text-stone-700">{{ $member->user->name }}</span>
                                    </li>
                                @endforeach
                            </ul>
                        @else
                            <p class="text-sm text-stone-400">No committee members</p>
                        @endif
                    </div>
                    <div class="border-t border-stone-100 px-5 py-3">
                        <a href="{{ route('provincial-committees.show', $province) }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-800">
                            View Details &rarr;
                        </a>
                    </div>
                </div>
            @endforeach
        </div>
    </div>
</x-layouts.app>
