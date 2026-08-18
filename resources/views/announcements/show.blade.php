<x-layouts.app :title="$announcement->title">
    <div class="max-w-4xl space-y-6">
        <div class="flex items-center justify-between">
            <div>
                <a href="{{ route('announcements.index') }}" class="text-sm font-medium text-emerald-700 hover:text-emerald-800">← All announcements</a>
                <h1 class="mt-1 font-heading text-3xl font-bold text-stone-900">{{ $announcement->title }}</h1>
            </div>
            @php
                $statusClass = match ($announcement->status->value) {
                    'draft' => 'bg-stone-100 text-stone-700',
                    'scheduled' => 'bg-sky-100 text-sky-700',
                    'sending' => 'bg-amber-100 text-amber-700',
                    'sent' => 'bg-emerald-100 text-emerald-700',
                    'cancelled' => 'bg-red-100 text-red-700',
                    default => 'bg-stone-100 text-stone-700',
                };
            @endphp
            <span class="inline-flex rounded-full px-3 py-1 text-xs font-semibold {{ $statusClass }}">
                {{ $announcement->status->label() }}
            </span>
        </div>

        @if (session('success'))
            <div class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('success') }}</div>
        @endif
        @if (session('error'))
            <div class="rounded-xl border border-red-200 bg-red-50 p-4 text-sm text-red-800">{{ session('error') }}</div>
        @endif

        @if ($announcement->needsApproval())
            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                <p class="text-sm font-semibold text-amber-900">This Policy change needs a second Exco/Chair to approve before sending.</p>
                <p class="mt-1 text-xs text-amber-700">The author cannot approve their own draft — another Exco or Chair member must click Approve.</p>
                @if(auth()->id() !== $announcement->created_by && auth()->user()->isExco())
                    <form method="POST" action="{{ route('announcements.approve', $announcement) }}" class="mt-3">
                        @csrf
                        <button type="submit" class="rounded-lg bg-amber-600 px-4 py-2 text-xs font-semibold text-white hover:bg-amber-700">Approve</button>
                    </form>
                @endif
            </div>
        @endif

        <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                <div>
                    <dt class="text-xs font-semibold uppercase text-stone-400">Category</dt>
                    <dd class="mt-1 text-stone-800">{{ $announcement->category->label() }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-stone-400">Priority</dt>
                    <dd class="mt-1 text-stone-800">{{ $announcement->priority->label() }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-stone-400">Author</dt>
                    <dd class="mt-1 text-stone-800">{{ $announcement->creator?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-stone-400">Approver</dt>
                    <dd class="mt-1 text-stone-800">{{ $announcement->approver?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-stone-400">Requires acknowledgement</dt>
                    <dd class="mt-1 text-stone-800">{{ $announcement->requires_acknowledgement ? 'Yes' : 'No' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-stone-400">Delivery</dt>
                    <dd class="mt-1 text-stone-800">
                        {{ collect([
                            $announcement->deliversVia(\App\Enums\DeliveryChannel::Database) ? 'In-app' : null,
                            $announcement->deliversVia(\App\Enums\DeliveryChannel::Mail) ? 'Email' : null,
                            $announcement->deliversVia(\App\Enums\DeliveryChannel::WebPush) ? 'Push' : null,
                        ])->filter()->implode(' · ') }}
                    </dd>
                </div>
                <div>
                    <dt class="text-xs font-semibold uppercase text-stone-400">Sent at</dt>
                    <dd class="mt-1 text-stone-800">{{ $announcement->sent_at?->format('d M Y H:i') ?? '—' }}</dd>
                </div>
            </dl>
        </div>

        <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
            <h2 class="font-heading text-base font-semibold text-stone-900">Body</h2>
            <div class="prose prose-stone mt-3 max-w-none text-sm text-stone-800">
                {!! \App\Support\AnnouncementBodyRenderer::toHtml((string) $announcement->body) !!}
            </div>
        </div>

        @if ($announcement->attachments->isNotEmpty())
            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                <h2 class="font-heading text-base font-semibold text-stone-900">Attachments</h2>
                <ul class="mt-3 divide-y divide-stone-100">
                    @foreach ($announcement->attachments as $attachment)
                        <li class="flex items-center justify-between py-2 gap-3">
                            <div class="flex items-center gap-3">
                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="size-5 text-stone-400">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="m18.375 12.739-7.693 7.693a4.5 4.5 0 0 1-6.364-6.364l10.94-10.94A3 3 0 1 1 19.5 7.372L8.552 18.32m.009-.01-.01.01m5.699-9.941-7.81 7.81a1.5 1.5 0 0 0 2.112 2.13"/>
                                </svg>
                                <div class="text-sm">
                                    <p class="font-medium text-stone-900">{{ $attachment->filename }}</p>
                                    <p class="text-xs text-stone-400">{{ $attachment->mime }} · {{ number_format($attachment->size / 1024, 1) }} KB</p>
                                </div>
                            </div>
                            <div class="flex items-center gap-2">
                                <a href="{{ route('announcements.attachment', [$announcement, $attachment]) }}"
                                    class="rounded-lg bg-white ring-1 ring-stone-200 px-3 py-1.5 text-xs font-semibold text-stone-700 hover:bg-stone-50">Download</a>
                                @if ($announcement->status->isEditable())
                                    <form method="POST" action="{{ route('announcements.attachment.destroy', [$announcement, $attachment]) }}"
                                        onsubmit="return confirm('Remove this attachment?');">
                                        @csrf
                                        @method('DELETE')
                                        <button type="submit" class="rounded-lg bg-red-50 px-3 py-1.5 text-xs font-semibold text-red-700 hover:bg-red-100">Remove</button>
                                    </form>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        @if ($announcement->status->isEditable())
            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm space-y-3">
                <h2 class="font-heading text-base font-semibold text-stone-900">Actions</h2>
                <div class="flex flex-wrap gap-3">
                    @if(! $announcement->needsApproval())
                        <form method="POST" action="{{ route('announcements.send', $announcement) }}">
                            @csrf
                            <button type="submit" class="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white hover:bg-emerald-800">Send now</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('announcements.cancel', $announcement) }}"
                        onsubmit="return confirm('Cancel this announcement? This is a one-way action for drafts / scheduled sends.');">
                        @csrf
                        <button type="submit" class="rounded-lg bg-red-50 px-4 py-2 text-sm font-semibold text-red-700 hover:bg-red-100">Cancel</button>
                    </form>
                </div>
            </div>
        @endif

        @if ($stats)
            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                <h2 class="font-heading text-base font-semibold text-stone-900">Delivery stats</h2>
                <dl class="mt-3 grid grid-cols-2 sm:grid-cols-4 gap-4 text-sm">
                    <div>
                        <dt class="text-xs font-semibold uppercase text-stone-400">Recipients</dt>
                        <dd class="mt-1 text-2xl font-semibold text-stone-900">{{ $stats['total'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase text-stone-400">Read</dt>
                        <dd class="mt-1 text-2xl font-semibold text-emerald-700">{{ $stats['read'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase text-stone-400">Unread</dt>
                        <dd class="mt-1 text-2xl font-semibold text-amber-700">{{ $stats['unread'] }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase text-stone-400">Acknowledged</dt>
                        <dd class="mt-1 text-2xl font-semibold text-emerald-700">
                            {{ $stats['acknowledged'] }}
                            @if($announcement->requires_acknowledgement)
                                <span class="text-xs font-medium text-stone-400">/ {{ $stats['total'] }}</span>
                            @endif
                        </dd>
                    </div>
                </dl>

                <h3 class="mt-6 font-heading text-sm font-semibold text-stone-900">Per-channel status</h3>
                <div class="mt-2 overflow-hidden rounded-lg border border-stone-200">
                    <table class="min-w-full divide-y divide-stone-200 text-xs">
                        <thead class="bg-stone-50 uppercase tracking-wide text-stone-500">
                            <tr>
                                <th class="px-3 py-2 text-left font-semibold">Channel</th>
                                <th class="px-3 py-2 text-left font-semibold">Sent</th>
                                <th class="px-3 py-2 text-left font-semibold">Queued</th>
                                <th class="px-3 py-2 text-left font-semibold">Failed</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-100">
                            @foreach ($stats['per_channel'] as $channel => $counts)
                                <tr>
                                    <td class="px-3 py-2 font-medium text-stone-800">{{ ucfirst($channel) }}</td>
                                    <td class="px-3 py-2 text-emerald-700">{{ $counts['sent'] }}</td>
                                    <td class="px-3 py-2 text-stone-500">{{ $counts['queued'] }}</td>
                                    <td class="px-3 py-2 text-red-700">{{ $counts['failed'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($announcement->requires_acknowledgement && $stats['outstanding_acknowledgements'] > 0)
                    <div class="mt-4 flex items-center gap-2">
                        <a href="{{ route('announcements.outstanding-csv', $announcement) }}"
                            class="inline-flex items-center gap-1 rounded-lg bg-amber-600 px-3 py-2 text-xs font-semibold text-white hover:bg-amber-700">
                            Download outstanding acknowledgements ({{ $stats['outstanding_acknowledgements'] }}) CSV
                        </a>
                    </div>
                @endif
            </div>

            @if ($recipients->isNotEmpty())
                <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                    <div class="flex items-baseline justify-between">
                        <h2 class="font-heading text-base font-semibold text-stone-900">Recipients</h2>
                        <p class="text-xs text-stone-400">Showing first {{ $recipients->count() }} of {{ $stats['total'] }}.</p>
                    </div>
                    <div class="mt-3 overflow-hidden rounded-lg border border-stone-200 overflow-x-auto">
                        <table class="min-w-full divide-y divide-stone-200 text-xs">
                            <thead class="bg-stone-50 uppercase tracking-wide text-stone-500">
                                <tr>
                                    <th class="px-3 py-2 text-left font-semibold">Recipient</th>
                                    <th class="px-3 py-2 text-left font-semibold">Read</th>
                                    @if ($announcement->requires_acknowledgement)
                                        <th class="px-3 py-2 text-left font-semibold">Acknowledged</th>
                                    @endif
                                    <th class="px-3 py-2 text-left font-semibold">In-app</th>
                                    <th class="px-3 py-2 text-left font-semibold">Email</th>
                                    <th class="px-3 py-2 text-left font-semibold">Push</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100">
                                @foreach ($recipients as $recipient)
                                    <tr>
                                        <td class="px-3 py-2 text-stone-800">
                                            <div class="font-medium">{{ $recipient['name'] }}</div>
                                            <div class="text-stone-400">{{ $recipient['email'] ?? '—' }}</div>
                                        </td>
                                        <td class="px-3 py-2 text-stone-500">
                                            {{ $recipient['read_at']?->format('d M H:i') ?? '—' }}
                                        </td>
                                        @if ($announcement->requires_acknowledgement)
                                            <td class="px-3 py-2 text-stone-500">
                                                {{ $recipient['acknowledged_at']?->format('d M H:i') ?? '—' }}
                                            </td>
                                        @endif
                                        @foreach (['database' => 'In-app', 'mail' => 'Email', 'webpush' => 'Push'] as $ch => $_)
                                            @php
                                                $s = $recipient['channels'][$ch] ?? null;
                                                $class = match ($s) {
                                                    'sent' => 'text-emerald-700',
                                                    'delivered' => 'text-emerald-700',
                                                    'queued' => 'text-stone-400',
                                                    'failed', 'bounced' => 'text-red-700',
                                                    default => 'text-stone-300',
                                                };
                                            @endphp
                                            <td class="px-3 py-2 font-medium {{ $class }}">{{ $s ?? '—' }}</td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        @endif
    </div>
</x-layouts.app>
