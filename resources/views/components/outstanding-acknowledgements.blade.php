@php
    // Announcements that require an ack and have not yet been ack'd by
    // this user. Ordered oldest-first so operators see the longest-standing
    // outstanding items at the top. Bounded to 5 in the UI to keep the
    // banner from swallowing the dashboard.
    $outstanding = auth()->check()
        ? \App\Models\AnnouncementRecipient::query()
            ->with('announcement:id,title,category,sent_at,requires_acknowledgement')
            ->where('user_id', auth()->id())
            ->whereNull('acknowledged_at')
            ->whereHas('announcement', function ($q) {
                $q->where('requires_acknowledgement', true)
                    ->whereNotNull('sent_at');
            })
            ->orderBy('id')
            ->limit(5)
            ->get()
        : collect();
@endphp

@if($outstanding->isNotEmpty())
    <div class="mb-6 rounded-xl border border-amber-300 bg-amber-50 p-4">
        <div class="flex items-start gap-3">
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="size-5 shrink-0 mt-0.5 text-amber-700">
                <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126ZM12 15.75h.007v.008H12v-.008Z"/>
            </svg>
            <div class="flex-1">
                <p class="text-sm font-semibold text-amber-900">
                    {{ $outstanding->count() }} outstanding acknowledgement{{ $outstanding->count() === 1 ? '' : 's' }}
                </p>
                <p class="text-xs text-amber-700 mt-0.5">Policy changes and other required notices are waiting for your confirmation.</p>
                <ul class="mt-2 space-y-1">
                    @foreach($outstanding as $recipient)
                        @php $a = $recipient->announcement; @endphp
                        @if($a)
                            <li>
                                <a href="{{ route('communications.show', $a) }}" class="text-sm font-medium text-amber-900 underline hover:text-amber-800">
                                    {{ $a->title }}
                                </a>
                                <span class="text-xs text-amber-700">— {{ $a->category->label() }} · {{ $a->sent_at?->format('d M Y') }}</span>
                            </li>
                        @endif
                    @endforeach
                </ul>
            </div>
        </div>
    </div>
@endif
