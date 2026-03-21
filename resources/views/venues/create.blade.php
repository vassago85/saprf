<x-layouts.app :title="'Add Venue - SAPRF'">
    <div class="max-w-2xl space-y-6">
        <div class="flex items-center justify-between">
            <h1 class="font-heading text-3xl font-bold text-stone-900">Add Venue</h1>
            <a href="{{ route('venues.index') }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-800">&larr; Back</a>
        </div>

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                <ul class="list-inside list-disc space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('venues.store') }}" class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            @csrf
            @include('venues._form', ['venue' => null])

            <div class="mt-6 flex items-center gap-3">
                <button type="submit" class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Save Venue
                </button>
                <a href="{{ route('venues.index') }}" class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts.app>
