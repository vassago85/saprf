<x-layouts.app :title="'Edit ' . $junior->name . ' - SAPRF'">
    <div class="max-w-3xl space-y-6">
        <div>
            <a href="{{ route('family.show', $junior) }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; {{ $junior->name }}</a>
            <h1 class="mt-2 font-heading text-3xl font-bold text-stone-900 tracking-tight">Edit Junior</h1>
        </div>

        <form method="POST" action="{{ route('family.update', $junior) }}" class="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
            @csrf
            @method('PUT')
            @include('family._form')
        </form>
    </div>
</x-layouts.app>
