<x-layouts.app :title="'Communications'">
    <div class="max-w-4xl space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900">Communications</h1>
                <p class="mt-1 text-sm text-stone-500">Every federation announcement sent to you.</p>
            </div>
            @if ($unreadCount > 0)
                <span class="inline-flex items-center rounded-full bg-emerald-100 px-2.5 py-0.5 text-xs font-semibold text-emerald-700">
                    {{ $unreadCount }} unread
                </span>
            @endif
        </div>

        <form method="GET" action="{{ route('communications.index') }}" class="rounded-xl border border-stone-200 bg-white p-4 shadow-sm">
            <div class="grid grid-cols-1 sm:grid-cols-5 gap-3 items-end">
                <div class="sm:col-span-2">
                    <label class="block text-xs font-semibold uppercase text-stone-400 mb-1">Search</label>
                    <input type="text" name="q" value="{{ request('q') }}" placeholder="Title or body"
                        class="block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-stone-400 mb-1">Category</label>
                    <select name="category" class="block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                        <option value="">All</option>
                        @foreach ($categories as $cat)
                            <option value="{{ $cat->value }}" @selected(request('category') === $cat->value)>{{ $cat->label() }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-semibold uppercase text-stone-400 mb-1">Show</label>
                    <select name="unread" class="block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                        <option value="">All</option>
                        <option value="1" @selected(request('unread') === '1')>Unread only</option>
                        <option value="0" @selected(request('unread') === '0')>Read only</option>
                    </select>
                </div>
                <div>
                    <button type="submit" class="w-full rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                        Filter
                    </button>
                </div>
            </div>
        </form>

        <div class="space-y-3">
            @forelse ($items as $recipient)
                @php $a = $recipient->announcement; @endphp
                @continue(! $a)
                <a href="{{ route('communications.show', $a) }}"
                    class="block rounded-xl border {{ $recipient->read_at ? 'border-stone-200' : 'border-emerald-300 bg-emerald-50/40' }} bg-white p-4 shadow-sm hover:shadow-md transition">
                    <div class="flex items-start justify-between gap-4">
                        <div>
                            <div class="flex items-center gap-2">
                                <span class="text-xs font-semibold uppercase text-stone-500">{{ $a->category->label() }}</span>
                                @if($a->requires_acknowledgement && ! $recipient->acknowledged_at)
                                    <span class="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-[10px] font-semibold text-amber-700">Acknowledgement required</span>
                                @endif
                                @if(! $recipient->read_at)
                                    <span class="inline-flex rounded-full bg-emerald-600 px-2 py-0.5 text-[10px] font-semibold text-white">New</span>
                                @endif
                            </div>
                            <h2 class="mt-1 font-heading text-lg font-semibold text-stone-900">{{ $a->title }}</h2>
                            <p class="mt-1 line-clamp-2 text-sm text-stone-600">{{ \Illuminate\Support\Str::limit(\App\Support\AnnouncementBodyRenderer::toPreview((string) $a->body), 200) }}</p>
                        </div>
                        <div class="text-right text-xs text-stone-500 shrink-0">
                            {{ $a->sent_at?->format('d M Y') }}
                        </div>
                    </div>
                </a>
            @empty
                <div class="rounded-xl border border-stone-200 bg-white p-8 text-center text-sm text-stone-400">
                    Nothing here yet.
                </div>
            @endforelse
        </div>

        <div>{{ $items->links() }}</div>
    </div>
</x-layouts.app>
