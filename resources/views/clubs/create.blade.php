<x-layouts.app :title="'New Club'">
    <div class="max-w-3xl space-y-6">
        <div>
            <a href="{{ route('clubs.index') }}" class="text-sm text-stone-500 hover:text-stone-800">← Back to clubs</a>
            <h1 class="mt-2 font-heading text-3xl font-bold text-stone-900 tracking-tight">New Club</h1>
        </div>

        <form method="POST" action="{{ route('clubs.store') }}" class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-6">
            @csrf
            @include('clubs._form')

            <div class="flex items-center gap-3 border-t border-stone-200 pt-4">
                <button type="submit" class="rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">Create Club</button>
                <a href="{{ route('clubs.index') }}" class="text-sm text-stone-500 hover:text-stone-800">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts.app>
