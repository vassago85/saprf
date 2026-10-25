{{--
    Script registration for the adminAddShooter Alpine component.
    Extracted so BOTH matches/edit.blade.php and the workspace Settings
    tab (which embeds _add-shooter-panel.blade.php) include a single
    source of truth — otherwise the two definitions silently drift.

    Registered as `window.adminAddShooter`, not via `Alpine.data()` under
    an `alpine:init` listener, because Livewire's `wire:navigate` keeps
    the same Alpine instance alive across pages. `alpine:init` fires
    exactly once per Alpine lifetime, so any registration done inside
    that listener on a subsequently-navigated page silently attaches a
    callback that will never run — and Alpine throws
    "adminAddShooter is not defined" the moment it hits x-data on the
    new page. A plain window global is resolved by Alpine's scope chain
    regardless of init timing, so it works on both first page load AND
    wire:navigate transitions.
--}}
@once
    @push('scripts')
        <script>
            window.adminAddShooter = function (config) {
                return {
                    query: '',
                    results: [],
                    loading: false,
                    selected: null,
                    showNewShooter: false,
                    newShooterName: '',
                    newShooterEmail: '',
                    searchUrl: config.searchUrl,
                    _abort: null,
                    init() {},
                    search() {
                        if (this.query.trim().length < 2) {
                            this.results = [];
                            return;
                        }
                        if (this._abort) { this._abort.abort(); }
                        this._abort = new AbortController();
                        this.loading = true;
                        fetch(this.searchUrl + '?q=' + encodeURIComponent(this.query.trim()), {
                            headers: { 'Accept': 'application/json' },
                            credentials: 'same-origin',
                            signal: this._abort.signal,
                        })
                            .then(r => r.ok ? r.json() : Promise.reject(r))
                            .then(data => { this.results = data.results || []; })
                            .catch(() => { /* ignored: transient network / abort */ })
                            .finally(() => { this.loading = false; });
                    },
                    pick(member) {
                        this.selected = member;
                        this.showNewShooter = false;
                        this.results = [];
                    },
                    startNewShooter() {
                        this.showNewShooter = true;
                        // Pre-fill the name from whatever they typed in the
                        // search box — most MDs will have typed the person's
                        // name already before realising they're not there.
                        if (this.query.trim().length >= 2 && this.newShooterName === '') {
                            this.newShooterName = this.query.trim();
                        }
                        this.results = [];
                    },
                    clear() {
                        this.selected = null;
                        this.showNewShooter = false;
                        this.newShooterName = '';
                        this.newShooterEmail = '';
                        this.query = '';
                        this.results = [];
                    },
                };
            };
        </script>
    @endpush
@endonce
