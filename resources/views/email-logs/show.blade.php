<x-layouts.app :title="'Email — ' . $log->subject">
    <div class="flex items-center gap-3 text-sm text-stone-500">
        <a href="{{ route('email-logs.index') }}" class="hover:text-emerald-700">Email Log</a>
        <span class="text-stone-300">/</span>
        <span class="text-stone-900">{{ Str::limit($log->subject, 60) }}</span>
    </div>

    <div class="mt-4 flex flex-wrap items-baseline justify-between gap-4">
        <h1 class="font-heading text-2xl font-bold text-stone-900">{{ $log->subject }}</h1>
        <div class="flex flex-wrap items-center gap-3">
            <span class="inline-flex items-center rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset {{ $log->statusPillClasses() }}">
                {{ $log->status === \App\Models\EmailLog::STATUS_DISMISSED ? 'Complete' : ucfirst($log->status) }}
            </span>
            @if ($log->canResend() && ! $mailgunPausedUntil)
                <form method="POST" action="{{ route('email-logs.resend', $log) }}"
                    onsubmit="return confirm('Send ONE email to {{ $log->to_email }} only?')">
                    @csrf
                    <button class="rounded-lg bg-sky-700 px-3.5 py-2 text-sm font-semibold text-white hover:bg-sky-800">Resend</button>
                </form>
            @endif
            @if ($log->isOutstanding())
                <form method="POST" action="{{ route('email-logs.dismiss', $log) }}"
                    onsubmit="return confirm('Mark this attempt as complete without sending?')">
                    @csrf
                    <button class="rounded-lg border border-stone-300 bg-white px-3.5 py-2 text-sm font-semibold text-stone-700 hover:bg-stone-50">Mark complete</button>
                </form>
            @endif
        </div>
    </div>

    <div class="mt-6 grid grid-cols-1 gap-6 lg:grid-cols-3">
        <div class="lg:col-span-2 space-y-4">
            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                <h2 class="text-sm font-semibold uppercase tracking-wider text-stone-500">Message</h2>
                @if ($log->body_redacted)
                    <p class="mt-3 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                        The body of this email was <strong>redacted</strong> at send time because the notification type
                        (<code class="text-amber-800">{{ class_basename($log->notification_class) }}</code>)
                        carries single-use credentials (password reset / OTP / invitation token).
                        Recording it here would let anyone with staff read access to this table steal an in-flight login token.
                    </p>
                @elseif ($log->body_html)
                    <div class="mt-3 max-h-[600px] overflow-auto rounded-lg border border-stone-200 bg-stone-50 p-4">
                        {{-- The stored HTML is what Mailgun sent. We don't sanitise it — it's an audit
                             record — but we DO sandbox it in an iframe so scripts (if any) can't run
                             against our origin and cookies. --}}
                        <iframe srcdoc="{{ $log->body_html }}" sandbox
                            class="min-h-[560px] w-full rounded-lg border border-stone-200 bg-white"></iframe>
                    </div>
                @elseif ($log->body_preview)
                    <pre class="mt-3 max-h-[400px] overflow-auto whitespace-pre-wrap rounded-lg border border-stone-200 bg-stone-50 p-4 text-sm text-stone-800">{{ $log->body_preview }}</pre>
                @else
                    <p class="mt-3 text-sm text-stone-400">No body was captured for this message.</p>
                @endif
            </div>

            @if ($log->error)
                <div class="rounded-xl border border-rose-200 bg-rose-50 p-6 shadow-sm">
                    <h2 class="text-sm font-semibold uppercase tracking-wider text-rose-700">Delivery error</h2>
                    <p class="mt-2 text-sm text-rose-900">{{ $log->error }}</p>
                </div>
            @endif
        </div>

        <div class="space-y-4">
            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                <h2 class="text-sm font-semibold uppercase tracking-wider text-stone-500">Envelope</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div>
                        <dt class="text-xs font-semibold uppercase text-stone-400">To</dt>
                        <dd class="text-stone-900">
                            {{ $log->to_email }}
                            @if ($log->to_name)
                                <span class="text-xs text-stone-500">({{ $log->to_name }})</span>
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase text-stone-400">From</dt>
                        <dd class="text-stone-900">{{ $log->from_email ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase text-stone-400">Reply-To</dt>
                        <dd class="text-stone-900">{{ $log->reply_to ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase text-stone-400">Mailer</dt>
                        <dd class="text-stone-900">{{ $log->mailer ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase text-stone-400">Notification type</dt>
                        <dd class="text-stone-900">
                            {{ $log->notification_class ? class_basename($log->notification_class) : 'Ad-hoc mail' }}
                        </dd>
                    </div>
                    @if ($log->user)
                        <div>
                            <dt class="text-xs font-semibold uppercase text-stone-400">Recipient user</dt>
                            <dd class="text-stone-900">
                                {{ $log->user->name }}
                                <span class="text-xs text-stone-500">(#{{ $log->user->id }})</span>
                            </dd>
                        </div>
                    @endif
                    @if ($log->message_id)
                        <div>
                            <dt class="text-xs font-semibold uppercase text-stone-400">Message-Id</dt>
                            <dd class="break-all text-xs text-stone-700">{{ $log->message_id }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                <h2 class="text-sm font-semibold uppercase tracking-wider text-stone-500">Timeline</h2>
                <dl class="mt-3 space-y-2 text-sm">
                    <div class="flex justify-between">
                        <dt class="text-stone-500">Queued</dt>
                        <dd class="text-stone-900">{{ $log->created_at->format('d M Y H:i:s') }}</dd>
                    </div>
                    @if ($log->sent_at)
                        <div class="flex justify-between">
                            <dt class="text-stone-500">Sent to Mailgun</dt>
                            <dd class="text-stone-900">{{ $log->sent_at->format('d M Y H:i:s') }}</dd>
                        </div>
                    @endif
                    @if ($log->delivered_at)
                        <div class="flex justify-between">
                            <dt class="text-emerald-700 font-semibold">Delivered</dt>
                            <dd class="text-emerald-800">{{ $log->delivered_at->format('d M Y H:i:s') }}</dd>
                        </div>
                    @endif
                    @if ($log->opened_at)
                        <div class="flex justify-between">
                            <dt class="text-stone-500">Opened</dt>
                            <dd class="text-stone-900">{{ $log->opened_at->format('d M Y H:i:s') }}</dd>
                        </div>
                    @endif
                    @if ($log->clicked_at)
                        <div class="flex justify-between">
                            <dt class="text-stone-500">Link clicked</dt>
                            <dd class="text-stone-900">{{ $log->clicked_at->format('d M Y H:i:s') }}</dd>
                        </div>
                    @endif
                    @if ($log->failed_at)
                        <div class="flex justify-between">
                            <dt class="text-amber-700 font-semibold">Failed</dt>
                            <dd class="text-amber-800">{{ $log->failed_at->format('d M Y H:i:s') }}</dd>
                        </div>
                    @endif
                    @if ($log->bounced_at)
                        <div class="flex justify-between">
                            <dt class="text-rose-700 font-semibold">Bounced</dt>
                            <dd class="text-rose-800">{{ $log->bounced_at->format('d M Y H:i:s') }}</dd>
                        </div>
                    @endif
                    @if ($log->complained_at)
                        <div class="flex justify-between">
                            <dt class="text-rose-700 font-semibold">Spam complaint</dt>
                            <dd class="text-rose-800">{{ $log->complained_at->format('d M Y H:i:s') }}</dd>
                        </div>
                    @endif
                    @if ($log->status === \App\Models\EmailLog::STATUS_DISMISSED)
                        <div class="flex justify-between">
                            <dt class="text-stone-500 font-semibold">Marked complete</dt>
                            <dd class="text-stone-700">{{ $log->updated_at->format('d M Y H:i:s') }}</dd>
                        </div>
                    @endif
                </dl>
            </div>

            @if ($log->context)
                <div class="rounded-xl border border-stone-200 bg-white p-6 shadow-sm">
                    <h2 class="text-sm font-semibold uppercase tracking-wider text-stone-500">Correlation</h2>
                    <pre class="mt-3 max-h-[240px] overflow-auto rounded-lg bg-stone-50 p-3 text-xs text-stone-700">{{ json_encode($log->context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                </div>
            @endif
        </div>
    </div>
</x-layouts.app>
