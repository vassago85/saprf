<x-layouts.app :title="'Appoint Committee Member - SAPRF'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Appoint Committee Member</h1>
                <p class="mt-1 text-sm text-stone-500">Assign a member to a provincial committee position.</p>
            </div>
            <a href="{{ route('provincial-committees.index') }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-800">&larr; Back to Committees</a>
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

        <form method="POST" action="{{ route('provincial-committees.store') }}"
            class="rounded-xl border border-stone-200 bg-white shadow-sm p-8 space-y-6 max-w-2xl">
            @csrf

            <div class="grid sm:grid-cols-2 gap-6">
                <div>
                    <label for="province_id" class="block text-sm font-medium text-stone-700 mb-1">Province</label>
                    <select name="province_id" id="province_id" required
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">Select province...</option>
                        @foreach ($provinces as $prov)
                            <option value="{{ $prov->id }}" @selected(old('province_id') == $prov->id)>{{ $prov->name }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="user_id" class="block text-sm font-medium text-stone-700 mb-1">Member</label>
                    <select name="user_id" id="user_id" required
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">Select member...</option>
                        @foreach ($users as $user)
                            <option value="{{ $user->id }}" @selected(old('user_id') == $user->id)>{{ $user->name }} ({{ $user->email }})</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="position" class="block text-sm font-medium text-stone-700 mb-1">Position</label>
                    <select name="position" id="position" required
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        <option value="">Select position...</option>
                        @foreach ($positions as $pos)
                            <option value="{{ $pos }}" @selected(old('position') === $pos)>{{ str_replace('_', ' ', ucfirst($pos)) }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="appointed_at" class="block text-sm font-medium text-stone-700 mb-1">Appointed Date</label>
                    <input type="date" name="appointed_at" id="appointed_at" value="{{ old('appointed_at', now()->format('Y-m-d')) }}" required
                        class="w-full rounded-lg border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <button type="submit"
                    class="rounded-xl bg-emerald-700 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Appoint Member
                </button>
                <a href="{{ route('provincial-committees.index') }}" class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts.app>
