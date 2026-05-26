<div class="sm:col-span-2" x-data="venueSelector({{ Js::from($venues->map(fn ($v) => [
    'id' => $v->id,
    'name' => $v->name,
    'city' => $v->city,
    'address' => $v->fullAddress(),
    'province_id' => $v->province_id,
])) }}, {{ Js::from($currentVenueName ?? '') }}, {{ Js::from($currentCity ?? '') }}, {{ Js::from($currentLocation ?? '') }})">

    <label for="venue_select" class="block text-sm font-medium text-stone-700 mb-1">Venue</label>
    <div class="flex items-center gap-2 mb-3">
        <select id="venue_select" x-model="selectedId" @change="applyVenue()" class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
            <option value="">— Select from address book —</option>
            <template x-for="v in venues" :key="v.id">
                <option :value="v.id" x-text="v.name + (v.city ? ' — ' + v.city : '')"></option>
            </template>
        </select>
        <button type="button" @click="clearVenue()" class="shrink-0 rounded-lg border border-stone-300 px-3 py-2 text-xs font-medium text-stone-600 hover:bg-stone-50 transition" title="Clear & enter manually">
            <svg class="size-4" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" d="m16.862 4.487 1.687-1.688a1.875 1.875 0 1 1 2.652 2.652L10.582 16.07a4.5 4.5 0 0 1-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 0 1 1.13-1.897l8.932-8.931Zm0 0L19.5 7.125" /></svg>
        </button>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label for="venue_name" class="block text-xs font-medium text-stone-500 mb-1">Venue Name</label>
            <input type="text" name="venue_name" id="venue_name" x-model="venueName" :readonly="locked" :class="locked ? 'bg-stone-50 text-stone-500' : ''" class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
        </div>
        <div>
            <label for="city" class="block text-xs font-medium text-stone-500 mb-1">City</label>
            <input type="text" name="city" id="city" x-model="city" :readonly="locked" :class="locked ? 'bg-stone-50 text-stone-500' : ''" class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
        </div>
    </div>

    <div class="mt-4">
        <label for="venue_location" class="block text-xs font-medium text-stone-500 mb-1">Venue Address / Directions</label>
        <input type="text" name="venue_location" id="venue_location" x-model="location" :readonly="locked" :class="locked ? 'bg-stone-50 text-stone-500' : ''" class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500" />
    </div>

    <template x-if="locked">
        <p class="mt-1.5 text-xs text-stone-400">Pulled from address book. Click the pencil to enter manually.</p>
    </template>
</div>

<script>
    function venueSelector(venues, initName, initCity, initLocation) {
        return {
            venues,
            selectedId: '',
            venueName: initName || '',
            city: initCity || '',
            location: initLocation || '',
            locked: false,

            init() {
                if (this.venueName) {
                    const match = this.venues.find(v => v.name === this.venueName);
                    if (match) {
                        this.selectedId = String(match.id);
                        this.locked = true;
                    }
                }
            },

            applyVenue() {
                const v = this.venues.find(v => String(v.id) === String(this.selectedId));
                if (v) {
                    this.venueName = v.name;
                    this.city = v.city || '';
                    this.location = v.address || '';
                    this.locked = true;

                    const provinceEl = document.getElementById('province_id');
                    if (provinceEl && v.province_id) {
                        provinceEl.value = v.province_id;
                        provinceEl.dispatchEvent(new Event('change'));
                    }
                }
            },

            clearVenue() {
                this.selectedId = '';
                this.venueName = '';
                this.city = '';
                this.location = '';
                this.locked = false;
            }
        }
    }
</script>
