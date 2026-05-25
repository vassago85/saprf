<x-layouts.app :title="'Edit Qualification Rule'">
    <div class="max-w-3xl">
        <a href="{{ route('qualification-rules.index') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; Qualification Rules</a>
        <h1 class="mt-2 font-heading text-3xl font-bold text-stone-900 tracking-tight">Edit Qualification Rule</h1>
        <p class="mt-1 text-sm text-stone-500">{{ $qualificationRule->series }} &mdash; Season {{ $qualificationRule->season }}</p>

        <form method="POST" action="{{ route('qualification-rules.update', $qualificationRule) }}" class="mt-8">
            @csrf
            @method('PUT')
            @include('qualification-rules._form')
        </form>
    </div>
</x-layouts.app>
