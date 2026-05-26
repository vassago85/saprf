<x-layouts.app :title="'Edit Committee Appointment - SAPRF'">
    <div class="space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900 tracking-tight">Edit Appointment</h1>
                <p class="mt-1 text-sm text-stone-500">Update this committee member's position or status.</p>
            </div>
            <a href="{{ route('provincial-committees.show', $appointment->province) }}" class="text-sm text-emerald-700 font-medium hover:text-emerald-800">&larr; Back to Province</a>
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

        <form method="POST" action="{{ route('provincial-committees.update', $appointment) }}"
            class="rounded-xl border border-stone-200 bg-white shadow-sm p-8 space-y-6 max-w-2xl">
            @csrf
            @method('PUT')

            <div class="grid sm:grid-cols-2 gap-6">
                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Province</label>
                    <input type="text" value="{{ $appointment->province->name ?? '—' }}" disabled
                        class="w-full rounded-lg border border-stone-200 bg-stone-50 text-sm py-2.5 px-3 text-stone-500">
                </div>

                <div>
                    <label class="block text-sm font-medium text-stone-700 mb-1">Member</label>
                    <input type="text" value="{{ $appointment->user->name }}" disabled
                        class="w-full rounded-lg border border-stone-200 bg-stone-50 text-sm py-2.5 px-3 text-stone-500">
                </div>

                <div>
                    <label for="position" class="block text-sm font-medium text-stone-700 mb-1">Position</label>
                    <select name="position" id="position" required
                        class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                        @foreach ($positions as $pos)
                            <option value="{{ $pos }}" @selected(old('position', $appointment->position) === $pos)>{{ str_replace('_', ' ', ucfirst($pos)) }}</option>
                        @endforeach
                    </select>
                </div>

                <div>
                    <label for="appointed_at" class="block text-sm font-medium text-stone-700 mb-1">Appointed Date</label>
                    <input type="date" name="appointed_at" id="appointed_at"
                        value="{{ old('appointed_at', $appointment->appointed_at?->format('Y-m-d')) }}" required
                        class="w-full rounded-lg border border-stone-300 text-sm py-2.5 px-3 focus:ring-emerald-500 focus:border-emerald-500">
                </div>

                <div class="sm:col-span-2">
                    <label class="flex items-center gap-3 cursor-pointer">
                        <input type="hidden" name="is_active" value="0">
                        <input type="checkbox" name="is_active" value="1"
                            @checked(old('is_active', $appointment->is_active))
                            class="rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500">
                        <span class="text-sm font-medium text-stone-700">Active appointment</span>
                    </label>
                    <p class="mt-1 ml-7 text-xs text-stone-400">Uncheck to deactivate this committee member without removing them.</p>
                </div>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <button type="submit"
                    class="rounded-xl bg-emerald-700 px-6 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800 transition">
                    Update Appointment
                </button>
                <a href="{{ route('provincial-committees.show', $appointment->province) }}" class="text-sm text-stone-500 hover:text-stone-700">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts.app>
