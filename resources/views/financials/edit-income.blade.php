<x-layouts.app :title="'Edit Income - SAPRF'">
    <div class="space-y-6">
        <div>
            <a href="{{ route('financials.income') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; Back to Income</a>
            <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight mt-2">Edit Income</h1>
        </div>

        <form method="POST" action="{{ route('financials.income.update', $income) }}" class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
            @csrf @method('PUT')
            @include('financials._income-form')
        </form>
    </div>
</x-layouts.app>
