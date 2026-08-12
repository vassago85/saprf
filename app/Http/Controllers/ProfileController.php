<?php

namespace App\Http\Controllers;

use App\Models\Club;
use App\Models\Province;
use App\Models\User;
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
     * "XX" is the ISO user-assigned code for "other/unknown".
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
            'genderOptions' => User::GENDER_OPTIONS,
            'ethnicityOptions' => User::ETHNICITY_OPTIONS,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'phone' => ['nullable', 'string', 'max:20'],
            // SASCOC requires an identity number for every member: a 13-digit SA
            // ID, or a passport number for non-citizens. One of the two is required.
            'sa_id_number' => ['nullable', 'required_without:passport_number', 'string', 'digits:13', Rule::unique('users')->ignore($user->id)],
            'passport_number' => ['nullable', 'required_without:sa_id_number', 'string', 'max:50'],
            'mil_le_number' => ['nullable', 'string', 'max:50'],
            'date_of_birth' => ['required', 'date', 'before:today'],
            'province_id' => ['required', 'exists:provinces,id'],
            'club_id' => ['required', 'exists:clubs,id'],
            'sa_citizen' => ['required', Rule::in(['0', '1'])],
            'country_of_residence' => ['required', Rule::in(array_keys(self::COUNTRIES))],
            // SASCOC demographic reporting is mandatory — SAPRF submits every
            // paid-up member's details, so no "prefer not to say" is allowed.
            'gender' => ['required', Rule::in(array_keys(User::GENDER_OPTIONS))],
            'ethnicity' => ['required', Rule::in(array_keys(User::ETHNICITY_OPTIONS))],
            'previously_disadvantaged_choice' => ['required', Rule::in(['yes', 'no'])],
            'current_password' => ['nullable', 'required_with:new_password', 'current_password'],
            'new_password' => ['nullable', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'sa_id_number.required_without' => 'Enter your 13-digit SA ID number, or a passport number if you are not a South African citizen.',
            'passport_number.required_without' => 'Enter a passport number, or your 13-digit SA ID number.',
            'date_of_birth.required' => 'Your date of birth is required for SASCOC reporting.',
            'gender.required' => 'Please select your gender for SASCOC reporting.',
            'ethnicity.required' => 'Please select your ethnicity for SASCOC reporting.',
            'previously_disadvantaged_choice.required' => 'Please indicate whether you are previously disadvantaged for SASCOC reporting.',
            'province_id.required' => 'Please select your province.',
            'club_id.required' => 'Please select your primary club.',
            'sa_citizen.required' => 'Please indicate whether you are a South African citizen (required for IPRF selection).',
            'country_of_residence.required' => 'Please select your country of residence.',
        ]);

        // sa_citizen is a nullable tri-state (yes / no / prefer-not-to-say).
        // The radio group posts '' for the third option; normalise to null.
        $saCitizen = $validated['sa_citizen'] ?? null;
        $validated['sa_citizen'] = $saCitizen === '' ? null : (bool) $saCitizen;

        // previously_disadvantaged is a tri-state select that arrives as a
        // string ('', 'yes', 'no'); persist as nullable boolean.
        $validated['previously_disadvantaged'] = match ($validated['previously_disadvantaged_choice'] ?? '') {
            'yes' => true,
            'no' => false,
            default => null,
        };

        if (! empty($validated['new_password'])) {
            $user->password = Hash::make($validated['new_password']);
        }

        unset(
            $validated['current_password'],
            $validated['new_password'],
            $validated['previously_disadvantaged_choice'],
        );

        $user->fill($validated);
        $user->save();

        return redirect()->route('profile')
            ->with('success', 'Profile updated successfully.');
    }
}
