<x-layouts.app :title="'Add Barrel - SAPRF'">
    <div class="space-y-6">
        <div>
            <a href="{{ route('barrels.index') }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-800">&larr; My Barrels</a>
            <h1 class="mt-1 font-heading text-3xl font-bold text-stone-900 tracking-tight">Add Barrel</h1>
        </div>

        <form method="POST" action="{{ route('barrels.store') }}"
              class="rounded-xl border border-stone-200 bg-white shadow-sm p-8 space-y-6 max-w-2xl">
            @csrf
            @include('barrels._form', ['rifles' => $rifles])
            <div class="flex items-center gap-4 pt-2">
                <button type="submit" class="rounded-xl bg-emerald-700 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Save Barrel
                </button>
                <a href="{{ route('barrels.index') }}" class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts.app>
