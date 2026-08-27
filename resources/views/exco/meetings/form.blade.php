<x-layouts.app :title="$meeting ? 'Edit meeting' : 'New meeting'">
    <div class="max-w-3xl space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <h1 class="font-heading text-3xl font-bold text-stone-900">
                    {{ $meeting ? 'Edit meeting' : 'New ExCo meeting' }}
                </h1>
                <p class="mt-1 text-sm text-stone-500">
                    {{ $meeting ? 'Update the sitting details. Agenda and minutes live on the meeting page.' : 'Set the sitting details. You will build the agenda on the next page.' }}
                </p>
            </div>
            <a href="{{ $meeting ? route('exco.meetings.show', $meeting) : route('exco.meetings.index') }}"
                class="text-sm font-medium text-emerald-700 hover:text-emerald-800">← Back</a>
        </div>

        @if ($errors->any())
            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                <ul class="list-inside list-disc space-y-1">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            </div>
        @endif

        <form method="POST"
            action="{{ $meeting ? route('exco.meetings.update', $meeting) : route('exco.meetings.store') }}"
            class="space-y-5 rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            @csrf
            @if ($meeting) @method('PUT') @endif

            <div>
                <label for="title" class="block text-sm font-medium text-stone-700">Title</label>
                <input id="title" type="text" name="title" required maxlength="200"
                    value="{{ old('title', $meeting?->title) }}"
                    placeholder="e.g. ExCo — August 2026"
                    class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm focus:border-emerald-500 focus:ring-1 focus:ring-emerald-500 focus:outline-none">
            </div>

            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                <div>
                    <label for="type" class="block text-sm font-medium text-stone-700">Type</label>
                    <select id="type" name="type"
                        class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                        @foreach ($types as $type)
                            <option value="{{ $type->value }}"
                                @selected(old('type', $meeting?->type->value) === $type->value)>
                                {{ $type->label() }}
                            </option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label for="scheduled_at" class="block text-sm font-medium text-stone-700">Scheduled at</label>
                    <input id="scheduled_at" type="datetime-local" name="scheduled_at" required
                        value="{{ old('scheduled_at', $meeting?->scheduled_at->format('Y-m-d\TH:i')) }}"
                        class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
                </div>
            </div>

            <div>
                <label for="location" class="block text-sm font-medium text-stone-700">Location (optional)</label>
                <input id="location" type="text" name="location" maxlength="200"
                    value="{{ old('location', $meeting?->location) }}"
                    placeholder="e.g. Zoom, or 12 Federation House"
                    class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">
            </div>

            <div>
                <label for="attendance_notes" class="block text-sm font-medium text-stone-700">Attendance / notes (optional)</label>
                <textarea id="attendance_notes" name="attendance_notes" rows="3" maxlength="5000"
                    placeholder="Who's expected, apologies received, etc."
                    class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm">{{ old('attendance_notes', $meeting?->attendance_notes) }}</textarea>
            </div>

            <div class="flex items-center gap-3 pt-2">
                <button type="submit"
                    class="rounded-xl bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white hover:bg-emerald-800">
                    {{ $meeting ? 'Save changes' : 'Create meeting' }}
                </button>
                @if ($meeting && ! $meeting->isClosed())
                    <form method="POST" action="{{ route('exco.meetings.destroy', $meeting) }}"
                        onsubmit="return confirm('Delete this meeting? Agenda items, minutes and actions on it will be removed. This cannot be undone.');">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                            class="rounded-xl bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-100">
                            Delete meeting
                        </button>
                    </form>
                @endif
            </div>
        </form>
    </div>
</x-layouts.app>
