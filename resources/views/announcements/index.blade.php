<x-layouts.app :title="'Announcements'">
    <div class="space-y-6">
        <x-page-header title="Announcements" subtitle="Federation-wide notifications sent from Exco to members.">
            <x-slot:actions>
                <a href="{{ route('announcements.create') }}"
                    class="inline-flex items-center gap-2 rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="size-4"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15"/></svg>
                    Compose
                </a>
            </x-slot:actions>
        </x-page-header>

        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                {{ session('success') }}
            </div>
        @endif
        @if (session('error'))
            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">
                {{ session('error') }}
            </div>
        @endif

        <x-admin-table>
            <table class="min-w-full divide-y divide-stone-200 text-sm">
                <thead class="bg-stone-50 text-xs uppercase tracking-wide text-stone-500">
                    <tr>
                        <th class="px-4 py-3 text-left font-semibold">Title</th>
                        <th class="px-4 py-3 text-left font-semibold">Category</th>
                        <th class="px-4 py-3 text-left font-semibold">Status</th>
                        <th class="px-4 py-3 text-left font-semibold">Recipients</th>
                        <th class="px-4 py-3 text-left font-semibold">By</th>
                        <th class="px-4 py-3 text-left font-semibold">Sent</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100">
                    @forelse ($announcements as $a)
                        @php
                            $statusClass = match ($a->status->value) {
                                'draft' => 'bg-stone-100 text-stone-700',
                                'scheduled' => 'bg-sky-100 text-sky-700',
                                'sending' => 'bg-amber-100 text-amber-700',
                                'sent' => 'bg-emerald-100 text-emerald-700',
                                'cancelled' => 'bg-red-100 text-red-700',
                                default => 'bg-stone-100 text-stone-700',
                            };
                        @endphp
                        <tr class="hover:bg-stone-50 {{ $a->isRetracted() ? 'bg-red-50/40' : '' }}">
                            <td class="px-4 py-3 font-medium text-stone-900">
                                <a href="{{ route('announcements.show', $a) }}" class="hover:text-emerald-700">
                                    {{ $a->display_title }}
                                </a>
                            </td>
                            <td class="px-4 py-3 text-stone-600">{{ $a->category->label() }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold {{ $statusClass }}">
                                    {{ $a->status->label() }}
                                </span>
                                @if ($a->isRetracted())
                                    <span class="ml-1 inline-flex rounded-full bg-red-600 px-2 py-0.5 text-xs font-semibold text-white">Retracted</span>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-stone-600">{{ $a->recipient_count }}</td>
                            <td class="px-4 py-3 text-stone-600">{{ $a->creator?->name ?? '—' }}</td>
                            <td class="px-4 py-3 text-stone-500">
                                {{ datetime_short($a->sent_at) }}
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="6" class="px-4 py-8 text-center text-sm text-stone-400">
                                No announcements yet. Compose the first one.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </x-admin-table>

        <div>{{ $announcements->links() }}</div>
    </div>
</x-layouts.app>
