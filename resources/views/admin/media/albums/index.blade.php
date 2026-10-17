<x-layouts.app :title="'Media Albums — SAPRF'">
    <div class="max-w-6xl mx-auto px-4 sm:px-6 py-8">
        <x-page-header title="Media Albums" subtitle="Photos from Team SA, SA Champs, Nationals and provincial matches." class="mb-6">
            <x-slot:actions>
                <a href="{{ route('media.albums.create') }}"
                   class="inline-flex items-center min-h-[44px] px-4 rounded-lg bg-emerald-700 text-white text-sm font-semibold hover:bg-emerald-800">
                    New album
                </a>
            </x-slot:actions>
        </x-page-header>

        @if(session('status'))
            <div class="mb-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 text-sm">
                {{ session('status') }}
            </div>
        @endif

        @if($albums->isEmpty())
            <div class="bg-white border border-stone-200 rounded-2xl p-10 text-center">
                <p class="text-stone-500">No albums yet. Create the first one.</p>
            </div>
        @else
            <div class="bg-white border border-stone-200 rounded-2xl overflow-hidden">
                <table class="w-full text-sm">
                    <thead class="bg-stone-50 text-stone-500 text-xs uppercase tracking-wider">
                        <tr>
                            <th class="text-left px-5 py-3">Album</th>
                            <th class="text-left px-5 py-3">Category</th>
                            <th class="text-left px-5 py-3">Date</th>
                            <th class="text-right px-5 py-3">Photos</th>
                            <th class="text-left px-5 py-3">Status</th>
                            <th class="px-5 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-100">
                        @foreach($albums as $album)
                            <tr>
                                <td class="px-5 py-3">
                                    <div class="font-semibold text-stone-900">{{ $album->title }}</div>
                                    <div class="text-xs text-stone-500 font-mono">{{ $album->slug }}</div>
                                </td>
                                <td class="px-5 py-3 text-stone-700">{{ $album->categoryLabel() }}</td>
                                <td class="px-5 py-3 text-stone-500">
                                    {{ $album->event_date?->format('j M Y') ?? '—' }}
                                </td>
                                <td class="px-5 py-3 text-right tabular-nums">{{ $album->items_count }}</td>
                                <td class="px-5 py-3">
                                    @if($album->is_published)
                                        <span class="inline-flex items-center rounded-full bg-emerald-100 text-emerald-800 px-2 py-0.5 text-[11px] font-semibold">Published</span>
                                    @else
                                        <span class="inline-flex items-center rounded-full bg-stone-100 text-stone-600 px-2 py-0.5 text-[11px] font-semibold">Draft</span>
                                    @endif
                                </td>
                                <td class="px-5 py-3 text-right space-x-3">
                                    <a href="{{ route('media.albums.items', ['album' => $album->slug]) }}" class="text-emerald-700 font-semibold hover:underline">Manage photos</a>
                                    <a href="{{ route('media.albums.edit', ['album' => $album->slug]) }}" class="text-stone-600 hover:underline">Edit</a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <div class="mt-4">{{ $albums->links() }}</div>
        @endif
    </div>
</x-layouts.app>
