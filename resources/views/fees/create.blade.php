<x-layouts.app :title="'Add Membership Fee'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Add Membership Fee</h1>
                <p class="mt-1 text-sm text-stone-500">Create a new annual membership fee tier.</p>
            </div>
            <a href="{{ route('fees.index') }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-800">← Back to Fees</a>
        </div>

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 p-4">
                <ul class="list-disc list-inside text-sm text-red-700 space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST" action="{{ route('fees.store') }}" class="rounded-xl border border-stone-200 bg-white shadow-sm p-8 space-y-6 max-w-2xl">
            @csrf
            @include('fees._form', ['tier' => null])

            <div class="flex items-center gap-4 pt-2">
                <flux:button type="submit" variant="primary" class="rounded-xl bg-emerald-700 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Add Fee
                </flux:button>
                <a href="{{ route('fees.index') }}" class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts.app>
