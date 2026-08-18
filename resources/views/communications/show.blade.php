<x-layouts.app :title="$announcement->title">
    <div class="max-w-3xl space-y-6">
        <div class="flex items-center justify-between">
            <a href="{{ route('communications.index') }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-800">← All communications</a>
            <span class="text-xs text-stone-500">{{ $announcement->sent_at?->format('d M Y H:i') }}</span>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-4">
            <div class="flex items-center gap-2">
                <span class="text-xs font-semibold uppercase text-stone-500">{{ $announcement->category->label() }}</span>
                @if ($announcement->category->isMandatory())
                    <span class="inline-flex rounded-full bg-red-100 px-2 py-0.5 text-[10px] font-semibold text-red-700">Mandatory</span>
                @endif
            </div>

            <h1 class="font-heading text-2xl font-bold text-stone-900">{{ $announcement->title }}</h1>

            <div class="prose prose-stone max-w-none text-sm text-stone-800">
                {!! \App\Support\AnnouncementBodyRenderer::toHtml((string) $announcement->body) !!}
            </div>

            @if ($announcement->attachments->isNotEmpty())
                <div class="border-t border-stone-100 pt-4">
                    <h2 class="text-xs font-semibold uppercase text-stone-500">Attachments</h2>
                    <ul class="mt-2 space-y-1">
                        @foreach ($announcement->attachments as $attachment)
                            <li>
                                <a href="{{ route('communications.attachment', ['announcement' => $announcement, 'attachment' => $attachment->id]) }}"
                                    class="text-sm text-emerald-700 hover:text-emerald-800 underline">
                                    {{ $attachment->filename }}
                                </a>
                                <span class="text-xs text-stone-400">({{ round($attachment->size / 1024) }} KB)</span>
                            </li>
                        @endforeach
                    </ul>
                </div>
            @endif

            <p class="text-xs text-stone-500 border-t border-stone-100 pt-4">
                Sent by {{ $announcement->creator?->name ?? 'SAPRF' }}.
            </p>
        </div>

        @if ($announcement->requires_acknowledgement)
            <div class="rounded-xl border {{ $recipient->acknowledged_at ? 'border-emerald-300 bg-emerald-50' : 'border-amber-300 bg-amber-50' }} p-4">
                @if ($recipient->acknowledged_at)
                    <p class="text-sm font-semibold text-emerald-800">You acknowledged this on {{ $recipient->acknowledged_at->format('d M Y H:i') }}.</p>
                @else
                    <div class="flex items-start gap-3">
                        <div class="flex-1">
                            <p class="text-sm font-semibold text-amber-900">Acknowledgement required</p>
                            <p class="mt-1 text-xs text-amber-700">Confirm you have read and understood this announcement.</p>
                        </div>
                        <form method="POST" action="{{ route('communications.acknowledge', $announcement) }}">
                            @csrf
                            <button type="submit" class="rounded-lg bg-amber-600 px-4 py-2 text-sm font-semibold text-white hover:bg-amber-700">
                                I acknowledge
                            </button>
                        </form>
                    </div>
                @endif
            </div>
        @endif
    </div>
</x-layouts.app>
