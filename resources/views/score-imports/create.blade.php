<x-layouts.app :title="'Upload Scores'">
    <h1 class="font-heading text-3xl font-bold text-stone-900">Upload Scores</h1>

    <div class="mt-6 border-t border-stone-200"></div>

    <form method="POST" action="{{ route('score-imports.store') }}" enctype="multipart/form-data" class="mt-6 max-w-xl space-y-6">
        @csrf

        <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6 space-y-6">
            <div>
                <label for="match_id" class="block text-sm font-medium text-stone-700 mb-1">Match <span class="text-red-500">*</span></label>
                <select name="match_id" id="match_id" required class="block w-full rounded-lg border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">Select match…</option>
                    @foreach ($matches as $match)
                        <option value="{{ $match->id }}" @selected(old('match_id') == $match->id)>{{ $match->name }} ({{ $match->match_date->format('d M Y') }})</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label for="source_type" class="block text-sm font-medium text-stone-700 mb-1">Source Type <span class="text-red-500">*</span></label>
                <select name="source_type" id="source_type" required class="block w-full rounded-lg border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
                    <option value="">Select source…</option>
                    <option value="csv" @selected(old('source_type') === 'csv')>CSV File</option>
                    <option value="practiscore" @selected(old('source_type') === 'practiscore')>PractiScore</option>
                    <option value="manual" @selected(old('source_type') === 'manual')>Manual Entry</option>
                </select>
            </div>

            <div>
                <label for="file" class="block text-sm font-medium text-stone-700 mb-1">Score File</label>
                <input
                    type="file"
                    name="file"
                    id="file"
                    accept=".csv,.xlsx,.xls"
                    class="block w-full text-sm text-stone-600
                        file:mr-4 file:rounded-lg file:border-0 file:bg-emerald-50 file:px-4 file:py-2
                        file:text-sm file:font-medium file:text-emerald-700
                        hover:file:bg-emerald-100"
                />
                @error('file')
                    <p class="mt-1 text-sm text-red-600">{{ $message }}</p>
                @enderror
            </div>
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

        <div class="flex items-center gap-3">
            <flux:button type="submit" variant="primary">Upload</flux:button>
            <flux:button href="{{ route('score-imports.index') }}" variant="ghost">Cancel</flux:button>
        </div>
    </form>
</x-layouts.app>
