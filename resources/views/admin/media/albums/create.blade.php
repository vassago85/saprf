<x-layouts.app :title="'New Album — SAPRF'">
    <div class="max-w-3xl mx-auto px-4 sm:px-6 py-8">
        <a href="{{ route('media.albums.index') }}" class="text-sm text-stone-500 hover:text-emerald-700 inline-flex items-center gap-1">
            <svg class="size-4" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5 3 12m0 0 7.5-7.5M3 12h18"/></svg>
            All albums
        </a>
        <h1 class="font-heading text-3xl font-bold text-stone-900 mt-3">New album</h1>

        <form method="POST" action="{{ route('media.albums.store') }}" class="mt-6 bg-white border border-stone-200 rounded-2xl p-6">
            @csrf
            @include('admin.media.albums._form', ['album' => $album, 'categories' => $categories, 'teams' => $teams, 'matches' => $matches])
            <div class="mt-6 flex gap-3">
                <button type="submit" class="inline-flex items-center min-h-[44px] px-5 rounded-lg bg-emerald-700 text-white text-sm font-semibold hover:bg-emerald-800">
                    Create album
                </button>
                <a href="{{ route('media.albums.index') }}" class="inline-flex items-center min-h-[44px] px-5 rounded-lg border border-stone-300 text-stone-700 text-sm font-semibold hover:bg-stone-50">
                    Cancel
                </a>
            </div>
        </form>
    </div>
</x-layouts.app>
