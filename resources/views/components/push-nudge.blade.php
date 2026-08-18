{{--
    One-time dismissible banner nudging authenticated members to turn on
    push notifications. Rendered near the top of every authed page from
    the app layout.

    Visibility rules — all must be true or the banner stays hidden:
      1. User is authenticated (server-side @auth guard below).
      2. User's `push_enabled` preference is not explicitly false — if they
         switched it off in their notification prefs we don't nag them.
      3. Browser supports Web Push (`window.saprfPush.supported`).
      4. This device doesn't already have an active subscription.
      5. User hasn't previously clicked "Not now" on THIS device
         (localStorage flag, keyed per-user so a shared device doesn't
         permanently silence the banner for other accounts).

    Clicking "Enable" runs the same subscribe flow as the profile page and
    reuses the friendly error copy from `resources/js/pwa.js`. Clicking
    "Not now" writes the dismissal flag; the banner won't return unless
    the user clears localStorage or signs in as a different account.

    Deliberately quiet: single-line copy, small pill buttons, no colour
    scream. Members will see it every session until they act — that's the
    only real nudge — but never during the same session twice.
--}}
@auth
    @php($pushPref = auth()->user()?->notificationPreference?->push_enabled ?? true)
    @if ($pushPref)
        <div
            x-data="pushNudge({{ auth()->id() }})"
            x-init="init()"
            x-show="visible"
            x-cloak
            class="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-900"
        >
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.75" stroke="currentColor" class="size-5 shrink-0">
                <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 0 0 5.454-1.31A8.967 8.967 0 0 1 18 9.75V9A6 6 0 0 0 6 9v.75a8.967 8.967 0 0 1-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 0 1-5.714 0m5.714 0a3 3 0 1 1-5.714 0"/>
            </svg>
            <div class="flex-1 min-w-[16rem]">
                <p class="font-semibold">Turn on push notifications for this device</p>
                <p class="text-xs text-emerald-800/80" x-text="statusMessage"></p>
            </div>
            <div class="flex items-center gap-2">
                <button type="button" @click="enable()" :disabled="working"
                    class="rounded-lg bg-emerald-700 px-3 py-1.5 text-xs font-semibold text-white hover:bg-emerald-800 disabled:opacity-50">
                    <span x-show="!working">Enable</span>
                    <span x-show="working">Working…</span>
                </button>
                <button type="button" @click="dismiss()"
                    class="rounded-lg bg-white/60 px-3 py-1.5 text-xs font-semibold text-emerald-900 hover:bg-white">
                    Not now
                </button>
            </div>
        </div>

        @once
            @push('scripts')
                <script>
                    function pushNudge(userId) {
                        const storageKey = `saprf.push-nudge-dismissed.${userId}`;

                        return {
                            visible: false,
                            working: false,
                            statusMessage: 'Get match updates, results, and important announcements straight to your phone.',

                            async init() {
                                if (!window.saprfPush || !window.saprfPush.supported) return;
                                if (localStorage.getItem(storageKey)) return;

                                try {
                                    const sub = await window.saprfPush.currentSubscription();
                                    this.visible = !sub;
                                } catch (e) {
                                    // Silent — if we can't even read the subscription
                                    // state, hiding the nudge is safer than showing a
                                    // button that will immediately fail.
                                    console.warn('[SAPRF push-nudge] init failed:', e);
                                }
                            },

                            async enable() {
                                this.working = true;
                                try {
                                    await window.saprfPush.subscribe();
                                    this.statusMessage = 'You\u2019re subscribed on this device.';
                                    setTimeout(() => { this.visible = false; }, 1500);
                                } catch (e) {
                                    this.statusMessage = e.message;
                                } finally {
                                    this.working = false;
                                }
                            },

                            dismiss() {
                                try { localStorage.setItem(storageKey, String(Date.now())); } catch (e) {}
                                this.visible = false;
                            },
                        };
                    }
                </script>
            @endpush
        @endonce
    @endif
@endauth
