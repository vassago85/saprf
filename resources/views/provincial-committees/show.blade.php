<x-layouts.app :title="$province->name . ' Committee - SAPRF'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <a href="{{ route('provincial-committees.index') }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-800">&larr; All Provinces</a>
                <h1 class="mt-1 font-heading text-3xl font-bold text-stone-900 tracking-tight">{{ $province->name }} Committee</h1>
            </div>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead>
                        <tr class="border-b-2 border-stone-200 bg-stone-50">
                            <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Position</th>
                            <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Name</th>
                            <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Email</th>
                            <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Appointed</th>
                            <th class="px-5 py-3.5 text-left text-[11px] font-semibold uppercase tracking-wider text-stone-400">Status</th>
                            <th class="px-5 py-3.5 text-right text-[11px] font-semibold uppercase tracking-wider text-stone-400">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @forelse ($members as $member)
                            <tr class="hover:bg-stone-50 transition-colors">
                                <td class="whitespace-nowrap px-5 py-3.5 text-sm">
                                    @switch($member->position)
                                        @case('chair')
                                            <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Chair</span>
                                            @break
                                        @case('vice_chair')
                                            <span class="inline-flex items-center rounded-full bg-sky-50 px-2.5 py-0.5 text-xs font-semibold text-sky-700 ring-1 ring-inset ring-sky-600/20">Vice Chair</span>
                                            @break
                                        @case('treasurer')
                                            <span class="inline-flex items-center rounded-full bg-amber-50 px-2.5 py-0.5 text-xs font-semibold text-amber-700 ring-1 ring-inset ring-amber-600/20">Treasurer</span>
                                            @break
                                        @case('secretary')
                                            <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-600 ring-1 ring-inset ring-stone-500/20">Secretary</span>
                                            @break
                                        @default
                                            <span class="inline-flex items-center rounded-full bg-stone-50 px-2.5 py-0.5 text-xs font-semibold text-stone-500 ring-1 ring-inset ring-stone-400/20">Member</span>
                                    @endswitch
                                </td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-sm font-medium text-stone-900">{{ $member->user->name }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-500">{{ $member->user->email }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-sm text-stone-500">{{ $member->appointed_at?->format('d M Y') ?? '—' }}</td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-sm">
                                    @if ($member->is_active)
                                        <span class="inline-flex items-center rounded-full bg-emerald-50 px-2.5 py-0.5 text-xs font-semibold text-emerald-700 ring-1 ring-inset ring-emerald-600/20">Active</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-stone-100 px-2.5 py-0.5 text-xs font-semibold text-stone-500 ring-1 ring-inset ring-stone-500/20">Inactive</span>
                                    @endif
                                </td>
                                <td class="whitespace-nowrap px-5 py-3.5 text-right text-sm">
                                    <a href="{{ route('provincial-committees.edit', $member) }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-900">Edit</a>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="6" class="px-5 py-12 text-center text-sm text-stone-400">No committee members appointed for this province.</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</x-layouts.app>
