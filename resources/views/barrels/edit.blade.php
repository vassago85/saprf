<x-layouts.app :title="'Edit Barrel - SAPRF'">
    <div class="space-y-6">
        <div>
            <a href="{{ route('barrels.index') }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-800">&larr; My Barrels</a>
            <h1 class="mt-1 font-heading text-3xl font-bold text-stone-900 tracking-tight">Edit Barrel</h1>
        </div>

        <div class="flex items-start gap-6">
            <form method="POST" action="{{ route('barrels.update', $barrel) }}"
                  class="rounded-xl border border-stone-200 bg-white shadow-sm p-8 space-y-6 max-w-2xl flex-1">
                @csrf
                @method('PUT')
                @include('barrels._form', ['rifles' => $rifles, 'barrel' => $barrel])
                <div class="flex items-center gap-4 pt-2">
                    <button type="submit" class="rounded-xl bg-emerald-700 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                        Save changes
                    </button>
                    <a href="{{ route('barrels.index') }}" class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>
                </div>
            </form>

            <form method="POST" action="{{ route('barrels.destroy', $barrel) }}"
                  onsubmit="return confirm('Remove this barrel?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="rounded-lg border border-red-200 bg-white px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-50 transition">
                    Delete
                </button>
            </form>
        </div>
    </div>
</x-layouts.app>
