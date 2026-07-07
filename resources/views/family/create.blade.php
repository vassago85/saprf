<x-layouts.app :title="'Add Family Member - SAPRF'">
    <div class="max-w-3xl space-y-6">
        <div>
            <a href="{{ route('family.index') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; My Family</a>
            <h1 class="mt-2 font-heading text-3xl font-bold text-stone-900 tracking-tight">Add a Family Member</h1>
            <p class="mt-1 text-sm text-stone-500">Create a managed account for someone in your family — a junior, your spouse, or another relative. They won't need their own email; you register them for matches and pay from your account.</p>
        </div>

        <form method="POST" action="{{ route('family.store') }}" class="rounded-2xl border border-stone-200 bg-white p-6 shadow-sm">
            @csrf
            @include('family._form')
        </form>
    </div>
</x-layouts.app>
