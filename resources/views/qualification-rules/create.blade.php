<x-layouts.app :title="'Add Qualification Rule'">
    <div class="max-w-3xl">
        <a href="{{ route('qualification-rules.index') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; Qualification Rules</a>
        <h1 class="mt-2 font-heading text-3xl font-bold text-stone-900 tracking-tight">Add Qualification Rule</h1>
        <p class="mt-1 text-sm text-stone-500">Define how shooters qualify for finals and how season standings are calculated for a given series and season.</p>

        <form method="POST" action="{{ route('qualification-rules.store') }}" class="mt-8">
            @csrf
            @include('qualification-rules._form')
        </form>
    </div>
</x-layouts.app>
