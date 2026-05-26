<div class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-5">
    <div class="sm:col-span-2">
        <label for="name" class="block text-sm font-medium text-stone-700 mb-1">Venue Name <span class="text-red-500">*</span></label>
        <input type="text" name="name" id="name" required value="{{ old('name', $venue->name ?? '') }}"
               class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
        @error('name') <p class="text-xs text-red-600 mt-1">{{ $message }}</p> @enderror
    </div>

    <div class="sm:col-span-2">
        <label for="address_line_1" class="block text-sm font-medium text-stone-700 mb-1">Address Line 1</label>
        <input type="text" name="address_line_1" id="address_line_1" value="{{ old('address_line_1', $venue->address_line_1 ?? '') }}"
               class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
    </div>

    <div class="sm:col-span-2">
        <label for="address_line_2" class="block text-sm font-medium text-stone-700 mb-1">Address Line 2</label>
        <input type="text" name="address_line_2" id="address_line_2" value="{{ old('address_line_2', $venue->address_line_2 ?? '') }}"
               class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
    </div>

    <div>
        <label for="city" class="block text-sm font-medium text-stone-700 mb-1">City</label>
        <input type="text" name="city" id="city" value="{{ old('city', $venue->city ?? '') }}"
               class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
    </div>

    <div>
        <label for="province_id" class="block text-sm font-medium text-stone-700 mb-1">Province</label>
        <select name="province_id" id="province_id"
                class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
            <option value="">— Select province</option>
            @foreach ($provinces as $prov)
                <option value="{{ $prov->id }}" @selected(old('province_id', $venue->province_id ?? '') == $prov->id)>{{ $prov->name }}</option>
            @endforeach
        </select>
    </div>

    <div>
        <label for="postal_code" class="block text-sm font-medium text-stone-700 mb-1">Postal Code</label>
        <input type="text" name="postal_code" id="postal_code" maxlength="10" value="{{ old('postal_code', $venue->postal_code ?? '') }}"
               class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
    </div>

    <div class="sm:col-span-2 border-t border-stone-100 pt-5 mt-1">
        <p class="text-xs font-semibold uppercase tracking-wide text-stone-400 mb-4">Contact Details</p>
    </div>

    <div>
        <label for="contact_name" class="block text-sm font-medium text-stone-700 mb-1">Contact Name</label>
        <input type="text" name="contact_name" id="contact_name" value="{{ old('contact_name', $venue->contact_name ?? '') }}"
               class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
    </div>

    <div>
        <label for="contact_phone" class="block text-sm font-medium text-stone-700 mb-1">Contact Phone</label>
        <input type="text" name="contact_phone" id="contact_phone" value="{{ old('contact_phone', $venue->contact_phone ?? '') }}"
               class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
    </div>

    <div class="sm:col-span-2">
        <label for="contact_email" class="block text-sm font-medium text-stone-700 mb-1">Contact Email</label>
        <input type="email" name="contact_email" id="contact_email" value="{{ old('contact_email', $venue->contact_email ?? '') }}"
               class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
    </div>

    <div class="sm:col-span-2 border-t border-stone-100 pt-5 mt-1">
        <p class="text-xs font-semibold uppercase tracking-wide text-stone-400 mb-4">GPS Coordinates (optional)</p>
    </div>

    <div>
        <label for="latitude" class="block text-sm font-medium text-stone-700 mb-1">Latitude</label>
        <input type="text" name="latitude" id="latitude" placeholder="-26.2041028" value="{{ old('latitude', $venue->latitude ?? '') }}"
               class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
    </div>

    <div>
        <label for="longitude" class="block text-sm font-medium text-stone-700 mb-1">Longitude</label>
        <input type="text" name="longitude" id="longitude" placeholder="28.0473051" value="{{ old('longitude', $venue->longitude ?? '') }}"
               class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">
    </div>

    <div class="sm:col-span-2">
        <label for="notes" class="block text-sm font-medium text-stone-700 mb-1">Notes / Directions</label>
        <textarea name="notes" id="notes" rows="3" maxlength="2000"
                  class="block w-full rounded-lg border border-stone-300 text-sm focus:ring-emerald-500 focus:border-emerald-500">{{ old('notes', $venue->notes ?? '') }}</textarea>
    </div>

    <div>
        <label class="flex items-center gap-2 cursor-pointer">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1"
                   class="rounded border border-stone-300 text-emerald-600 focus:ring-emerald-500"
                   @checked(old('is_active', $venue->is_active ?? true))>
            <span class="text-sm font-medium text-stone-700">Active</span>
        </label>
    </div>
</div>
