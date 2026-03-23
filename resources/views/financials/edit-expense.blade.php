<x-layouts.app :title="'Edit Expense - SAPRF'">
    <div class="space-y-6">
        <div>
            <a href="{{ route('financials.expenses') }}" class="text-sm text-emerald-700 hover:text-emerald-800 font-medium">&larr; Back to Expenses</a>
            <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight mt-2">Edit Expense</h1>
        </div>

        <form method="POST" action="{{ route('financials.expenses.update', $expense) }}" class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
            @csrf @method('PUT')
            @include('financials._expense-form')
        </form>
    </div>
</x-layouts.app>
