<x-layouts.app :title="'Edit Album — SAPRF'">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 py-8">
        <a href="{{ route('media.albums.index') }}" class="text-sm text-stone-500 hover:text-emerald-700 inline-flex items-center gap-1">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
            All albums
        </a>
        <h1 class="font-heading text-3xl font-bold text-stone-900 mt-3">Edit album</h1>
        <p class="text-sm text-stone-500 mt-1">
            <a href="{{ route('media.albums.items', ['album' => $album->slug]) }}" class="text-emerald-700 font-semibold hover:underline">Manage photos</a>
            —
            <a href="{{ route('media.show', ['album' => $album->slug]) }}" target="_blank" class="hover:underline">View public page ↗</a>
        </p>

        @if(session('status'))
            <div class="mt-4 rounded-lg bg-emerald-50 border border-emerald-200 text-emerald-800 px-4 py-3 text-sm">
                {{ session('status') }}
            </div>
        @endif

        <form method="POST" action="{{ route('media.albums.update', ['album' => $album->slug]) }}" class="mt-6 bg-white border border-stone-200 rounded-2xl p-6">
            @csrf
            @method('PATCH')
            @include('admin.media.albums._form', ['album' => $album, 'categories' => $categories, 'teams' => $teams, 'matches' => $matches])
            <div class="mt-6 flex items-center justify-between">
                <button type="submit" class="inline-flex items-center min-h-[44px] px-5 rounded-lg bg-emerald-700 text-white text-sm font-semibold hover:bg-emerald-800">
                    Save
                </button>
                <button type="submit"
                        formaction="{{ route('media.albums.destroy', ['album' => $album->slug]) }}"
                        formmethod="POST"
                        onclick="event.preventDefault(); if(confirm('Delete this album and all its photos? This cannot be undone.')) { this.form.action='{{ route('media.albums.destroy', ['album' => $album->slug]) }}'; this.form.method='POST'; const m=document.createElement('input'); m.type='hidden'; m.name='_method'; m.value='DELETE'; this.form.appendChild(m); this.form.submit(); }"
                        class="text-sm text-red-700 hover:text-red-900 font-semibold">
                    Delete album
                </button>
            </div>
        </form>
    </div>
</x-layouts.app>
