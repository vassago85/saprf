<x-layouts.app :title="'Add Expense - SAPRF'">
    <div class="space-y-6">
        <div>
            <a href="{{ route('financials.expenses') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; Back to Expenses</a>
            <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight mt-2">Add Expense</h1>
            <p class="mt-1 text-sm text-stone-500">Record a platform operating expense.</p>
        </div>

        <form method="POST" action="{{ route('financials.expenses.store') }}" class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
            @csrf
            @include('financials._expense-form')
        </form>
    </div>
</x-layouts.app>
