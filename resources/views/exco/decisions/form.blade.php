@php
    $isEdit = $decision !== null;
    $title = $isEdit ? 'Edit decision' : 'New decision';
@endphp

<x-layouts.app :title="$title">
    <div class="mx-auto max-w-2xl space-y-6">
        <div>
            <a href="{{ route('exco.decisions.index') }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-800">← Decisions</a>
            <h1 class="mt-2 font-heading text-3xl font-bold text-stone-900">{{ $title }}</h1>
            <p class="mt-1 text-sm text-stone-500">
                @if ($isEdit && $decision->isPublished())
                    Published {{ $decision->published_at->timezone(config('app.timezone'))->format('d M Y') }}.
                    You can correct the wording; the publish date stays fixed (the reaction window is counted from that date).
                @else
                    Save as draft, or publish straight to the public register.
                @endif
            </p>
        </div>

        <form method="POST"
              action="{{ $isEdit ? route('exco.decisions.update', $decision) : route('exco.decisions.store') }}"
              class="space-y-5 rounded-2xl border border-stone-200 bg-white p-6">
            @csrf
            @if ($isEdit)
                @method('PUT')
            @endif

            <div>
                <label for="title" class="block text-xs font-semibold uppercase text-stone-500">Title</label>
                <input type="text" name="title" id="title" required maxlength="200"
                       value="{{ old('title', $decision?->title) }}"
                       class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm @error('title') border-red-400 @enderror">
                @error('title')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="reference" class="block text-xs font-semibold uppercase text-stone-500">Reference <span class="font-normal normal-case text-stone-400">(optional)</span></label>
                <input type="text" name="reference" id="reference" maxlength="200"
                       value="{{ old('reference', $decision?->reference) }}"
                       placeholder="e.g. ExCo, 11 Nov 2026"
                       class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm @error('reference') border-red-400 @enderror">
                @error('reference')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label for="body" class="block text-xs font-semibold uppercase text-stone-500">Decision</label>
                <textarea name="body" id="body" required rows="8" maxlength="10000"
                          class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm @error('body') border-red-400 @enderror">{{ old('body', $decision?->body) }}</textarea>
                @error('body')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex flex-wrap items-center gap-2 border-t border-stone-100 pt-4">
                <button type="submit" name="publish" value="0"
                        class="rounded-xl bg-stone-100 px-4 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-200">
                    {{ $isEdit && $decision->isPublished() ? 'Save changes' : 'Save draft' }}
                </button>
                @if (! $isEdit || $decision->isDraft())
                    <button type="submit" name="publish" value="1"
                            class="rounded-xl bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">
                        Publish
                    </button>
                @endif
                <a href="{{ route('exco.decisions.index') }}" class="ml-auto text-sm text-stone-500 hover:text-stone-700">Cancel</a>
            </div>
        </form>
    </div>
</x-layouts.app>
