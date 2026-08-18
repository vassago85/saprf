<x-layouts.app :title="'Message Entrants — ' . $match->name">
    <div class="flex items-center justify-between">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wide text-stone-400">Message Entrants</p>
            <h1 class="font-heading text-3xl font-bold text-stone-900">{{ $match->name }}</h1>
            <p class="mt-1 text-sm text-stone-500">
                {{ $match->match_date?->format('D, j M Y') }}
                @if($match->venue_name)
                    &middot; {{ $match->venue_name }}
                @endif
            </p>
        </div>
        <flux:button href="{{ route('matches.show', $match) }}" variant="ghost" icon="arrow-left">Back</flux:button>
    </div>

    <div class="mt-6 border-t border-stone-200"></div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-6">
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <h2 class="text-lg font-semibold text-stone-900 mb-2">Compose message</h2>
                <p class="text-sm text-stone-500 mb-5">
                    This will email
                    <span class="font-semibold text-stone-800">{{ $recipientCount }}</span>
                    {{ \Illuminate\Support\Str::plural('entrant', $recipientCount) }}
                    (confirmed + waitlisted). Managed juniors' parents receive it on their behalf. Cancelled and pending entrants will not receive this message.
                </p>

                @if(! $notificationsEnabled)
                    <div class="mb-5 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                        <div class="flex items-start gap-3">
                            <flux:icon.exclamation-triangle class="size-5 shrink-0 text-amber-600" />
                            <div>
                                <p class="font-semibold">Outgoing email is paused.</p>
                                <p class="mt-1">
                                    Global notifications are currently disabled in Site Settings. The announcement will be queued but no email will actually be sent until an administrator re-enables notifications.
                                </p>
                            </div>
                        </div>
                    </div>
                @endif

                @if($errors->any())
                    <div class="mb-5 rounded-lg border border-red-200 bg-red-50 p-4 text-sm text-red-700">
                        <ul class="list-disc pl-5 space-y-1">
                            @foreach($errors->all() as $error)
                                <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                <form method="POST" action="{{ route('matches.announcements.store', $match) }}" class="space-y-5"
                      x-data="{ subject: @js(old('subject', '')), body: @js(old('body', '')) }">
                    @csrf

                    <div>
                        <div class="flex items-center justify-between">
                            <label for="subject" class="block text-sm font-medium text-stone-700">Subject</label>
                            <span class="text-xs text-stone-400" x-text="subject.length + ' / 200'"></span>
                        </div>
                        <input type="text" id="subject" name="subject" maxlength="200" required
                               value="{{ old('subject') }}"
                               x-model="subject"
                               placeholder="e.g. Stage layout change for Saturday"
                               class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500">
                    </div>

                    <div>
                        <div class="flex items-center justify-between">
                            <label for="body" class="block text-sm font-medium text-stone-700">Message</label>
                            <span class="text-xs text-stone-400" x-text="body.length + ' / 5000'"></span>
                        </div>
                        <textarea id="body" name="body" rows="12" maxlength="5000" required
                                  x-model="body"
                                  placeholder="Plain text works. Optional: **bold**, *italic*, [link](https://…), lines starting with - for bullets. Bare URLs become clickable."
                                  class="mt-1 block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-emerald-500 focus:outline-none focus:ring-1 focus:ring-emerald-500 font-mono">{{ old('body') }}</textarea>
                        <p class="mt-1.5 text-xs text-stone-400">Recipients see this message signed as coming from the match director of {{ $match->name }}. Replies are routed to your account email. Basic markdown (<code class="rounded bg-stone-100 px-1">**bold**</code>, <code class="rounded bg-stone-100 px-1">- bullet</code>, <code class="rounded bg-stone-100 px-1">[link](url)</code>) is supported.</p>
                    </div>

                    <div class="flex items-center justify-end gap-3 pt-2 border-t border-stone-100">
                        <a href="{{ route('matches.show', $match) }}" class="text-sm font-medium text-stone-500 hover:text-stone-700">Cancel</a>
                        <flux:button type="submit" variant="primary" icon="paper-airplane"
                                     :disabled="$recipientCount === 0">
                            Send to {{ $recipientCount }} {{ \Illuminate\Support\Str::plural('entrant', $recipientCount) }}
                        </flux:button>
                    </div>
                </form>
            </div>
        </div>

        <div class="space-y-6">
            <div class="rounded-xl border border-stone-200 bg-white shadow-sm p-6">
                <h2 class="text-lg font-semibold text-stone-900 mb-4">Recent bulletins</h2>
                <p class="mb-3 text-xs text-stone-400">
                    Bulletins auto-hide from entrants' inbox and archive when this match is marked completed or cancelled.
                </p>

                @forelse($recentAnnouncements as $prev)
                    <div class="py-3 first:pt-0 last:pb-0 border-b border-stone-100 last:border-b-0">
                        <p class="text-sm font-medium text-stone-900 truncate">{{ $prev->title }}</p>
                        <p class="mt-1 text-xs text-stone-500">
                            {{ $prev->sent_at?->format('d M Y H:i') }}
                            &middot; {{ $prev->recipient_count }} {{ \Illuminate\Support\Str::plural('recipient', $prev->recipient_count) }}
                            @if($prev->creator)
                                &middot; {{ $prev->creator->name }}
                            @endif
                        </p>
                    </div>
                @empty
                    <p class="text-sm text-stone-400">No bulletins have been sent yet.</p>
                @endforelse
            </div>
        </div>
    </div>
</x-layouts.app>
