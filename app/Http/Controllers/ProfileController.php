<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Province;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Countries we surface in the residence dropdown. Kept small because the
     * only functional consequence today is IPRF eligibility (SA vs. abroad);
     * "OTHER" is a valid fallback for anyone not in this list.
     */
    private const COUNTRIES = [
        'ZA' => 'South Africa',
        'GB' => 'United Kingdom',
        'US' => 'United States',
        'AU' => 'Australia',
        'NZ' => 'New Zealand',
        'CA' => 'Canada',
        'NA' => 'Namibia',
        'BW' => 'Botswana',
        'ZW' => 'Zimbabwe',
        'AE' => 'United Arab Emirates',
        'DE' => 'Germany',
        'NL' => 'Netherlands',
        'IE' => 'Ireland',
        'CH' => 'Switzerland',
        'XX' => 'Other',
    ];

    public function edit(Request $request): View
    {
        $clubs = Club::query()
            ->where('is_active', true)
            ->with('province:id,name')
            ->orderBy('name')
            ->get()
            ->groupBy(fn (Club $c) => $c->province?->name ?? 'Unassigned');

        return view('profile.edit', [
            'user' => $request->user(),
            'provinces' => Province::orderBy('name')->get(),
            'clubs' => $clubs,
            'countries' => self::COUNTRIES,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            'sa_id_number' => ['nullable', 'string', 'digits:13', Rule::unique('users')->ignore($user->id)],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'province_id' => ['nullable', 'exists:provinces,id'],
            'club_id' => ['nullable', 'exists:clubs,id'],
            'sa_citizen' => ['nullable', Rule::in(['0', '1', ''])],
            'country_of_residence' => ['nullable', Rule::in(array_keys(self::COUNTRIES))],
            'current_password' => ['nullable', 'required_with:new_password', 'current_password'],
            'new_password' => ['nullable', 'confirmed', Password::min(8)->letters()->numbers()],
        ]);

        // sa_citizen is a nullable tri-state (yes / no / prefer-not-to-say).
        // The radio group posts '' for the third option; normalise to null.
        $saCitizen = $validated['sa_citizen'] ?? null;
        $validated['sa_citizen'] = $saCitizen === '' ? null : (bool) $saCitizen;

        if (! empty($validated['new_password'])) {
            $user->password = Hash::make($validated['new_password']);
        }

        unset($validated['current_password'], $validated['new_password']);

        $user->fill($validated);
        $user->save();

        return redirect()->route('profile')
            ->with('success', 'Profile updated successfully.');
    }
}
