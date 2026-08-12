<x-layouts.app :title="'Edit Selection Cycle'">
    <div class="max-w-3xl space-y-6">
        <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Edit {{ $cycle->series }} {{ $cycle->season }}</h1>
        <form method="POST" action="{{ route('selection.cycles.update', $cycle) }}" class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-4">
            @csrf
            @method('PUT')
            @include('selection.cycles._form', ['cycle' => $cycle])
            <div class="flex items-center justify-end gap-2 pt-4">
                <a href="{{ route('selection.cycles.show', $cycle) }}" class="rounded-lg border border-stone-300 bg-white px-4 py-2 text-sm font-medium text-stone-700 hover:bg-stone-50">Cancel</a>
                <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Save</button>
            </div>
        </form>
    </div>
</x-layouts.app>
