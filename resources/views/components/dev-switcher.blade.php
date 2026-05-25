@if($devSwitcherEnabled ?? false)
<div x-data="{ open: true }" x-show="open" x-transition
     class="fixed bottom-0 inset-x-0 z-50 bg-stone-900/95 backdrop-blur border-t border-stone-700 shadow-2xl">
    <div class="max-w-4xl mx-auto px-4 py-3 flex items-center justify-between gap-4">
        <div class="flex items-center gap-3">
            <span class="inline-flex items-center rounded-md bg-amber-500/20 px-2 py-1 text-[10px] font-bold uppercase tracking-widest text-amber-400 ring-1 ring-inset ring-amber-500/30">DEV</span>
            <span class="text-xs text-stone-400 hidden sm:inline">View as:</span>
        </div>

        <div class="flex items-center gap-2">
            @php
                $roles = [
                    'developer' => ['label' => 'Developer', 'icon' => 'code-bracket', 'color' => 'violet'],
                    'owner' => ['label' => 'Owner', 'icon' => 'crown', 'color' => 'amber'],
                    'admin' => ['label' => 'Admin', 'icon' => 'shield', 'color' => 'sky'],
                    'match_director' => ['label' => 'Match Director', 'icon' => 'flag', 'color' => 'violet'],
                    'provincial_admin' => ['label' => 'Provincial Admin', 'icon' => 'building-library', 'color' => 'teal'],
                    'member' => ['label' => 'Member', 'icon' => 'user', 'color' => 'emerald'],
                ];
            @endphp

            @foreach($roles as $role => $meta)
                <a href="{{ route('dashboard', ['view_as' => $role]) }}"
                   class="inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-semibold transition-all
                          {{ ($currentViewAs ?? '') === $role
                              ? 'bg-white text-stone-900 shadow-sm'
                              : 'text-stone-400 hover:text-white hover:bg-stone-700/50' }}">
                    {{ $meta['label'] }}
                </a>
            @endforeach
        </div>

        <div class="flex items-center gap-2">
            <a href="{{ route('dashboard') }}" class="text-[10px] text-stone-500 hover:text-stone-300 transition">Reset</a>
            <button @click="open = false" class="text-stone-500 hover:text-stone-300 transition p-1">
                <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18 18 6M6 6l12 12" /></svg>
            </button>
        </div>
    </div>
</div>
@endif
