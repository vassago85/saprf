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

        $user = $request->user();
        $pref = $user->notificationPreference;

        return view('profile.edit', [
            'user' => $user,
            'provinces' => Province::orderBy('name')->get(),
            'clubs' => $clubs,
            'countries' => self::COUNTRIES,
            'genderOptions' => User::GENDER_OPTIONS,
            'ethnicityOptions' => User::ETHNICITY_OPTIONS,
            // Notification preferences panel. The view uses these directly
            // to render the mute checkboxes + push toggle; keeping them here
            // (rather than in a `@php` block in the Blade file) sidesteps a
            // scope quirk where Blade component slots hid `@php` vars.
            'mutedCategories' => $pref?->muted_email_categories ?? [],
            'pushEnabled' => $pref?->push_enabled ?? true,
            'mutableCategories' => collect(\App\Enums\AnnouncementCategory::cases())
                ->reject(fn ($c) => $c->isMandatory())
                ->values(),
            // Public-profile visibility radio group. Same scope-quirk
            // sidestep — the `@php` block that used to sit inside the
            // fieldset was silently emptied when nested inside the
            // <x-layouts.app> slot, so we build the meta here.
            'visibilityOptions' => [
                User::PROFILE_VISIBILITY_PUBLIC => [
                    'label' => 'Public',
                    'helper' => 'Anyone with the link (including search engines) can view your profile.',
                    'accent' => 'emerald',
                ],
                User::PROFILE_VISIBILITY_MEMBERS_ONLY => [
                    'label' => 'Members only',
                    'helper' => 'Only signed-in SAPRF members can view your profile — guests get a 404.',
                    'accent' => 'blue',
                ],
                User::PROFILE_VISIBILITY_HIDDEN => [
                    'label' => 'Hidden',
                    'helper' => 'Your profile page returns 404 for everyone except you and SAPRF staff. Your season standings are still visible in leaderboards.',
                    'accent' => 'stone',
                ],
            ],
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        // Legacy imports and mobile keyboards frequently deposit non-digit
        // characters (spaces, hyphens, non-breaking spaces) into the SA ID
        // field. Strip them before validation so `digits:13` doesn't reject a
        // value that is otherwise correct, and null-out an empty passport
        // string so `required_without` behaves symmetrically.
        $request->merge([
            'sa_id_number' => self::normaliseSaIdNumber($request->input('sa_id_number')),
            'passport_number' => self::normalisePassportNumber($request->input('passport_number')),
        ]);

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
            // Public shooter profile POPIA control. Members choose whether
            // /shooters/{saprfNumber} is visible to guests, members only, or
            // hidden (404) entirely. Enum-validated so a crafted request
            // can't inject a value outside the migration's enum.
            'public_profile_visibility' => ['required', Rule::in(array_keys(User::PROFILE_VISIBILITY_OPTIONS))],
        ], [
            'sa_id_number.required_without' => 'Enter your 13-digit SA ID number, or a passport number if you are not a South African citizen.',
            'passport_number.required_without' => 'Enter a passport number, or your 13-digit SA ID number.',
            'sa_id_number.digits' => 'SA ID number must be exactly 13 digits.',
            'sa_id_number.unique' => 'That SA ID is already on another account. Please contact the SAPRF admins.',
            'email.unique' => 'That email address is already in use by another member.',
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

        unset($validated['previously_disadvantaged_choice']);

        $user->fill($validated);
        $user->save();

        return redirect()->route('profile')
            ->with('success', 'Profile updated successfully.');
    }

    /**
     * Password change lives on its own form + route. Bundling it with the
     * profile update meant Safari + iCloud Keychain silently autofilled the
     * `current_password` field on unrelated saves and Laravel then rejected
     * the whole form with "current password is incorrect" — even for members
     * who only wanted to change their phone number. Splitting the two also
     * means we can require both fields here without having to make them
     * optional-when-empty on the details form.
     */
    public function updatePassword(Request $request): RedirectResponse
    {
        $user = $request->user();

        $request->validate([
            'current_password' => ['required', 'current_password'],
            'new_password' => ['required', 'confirmed', Password::min(8)->letters()->numbers()],
        ], [
            'current_password.current_password' => 'The current password you entered is incorrect.',
            'new_password.confirmed' => 'The new password and confirmation do not match.',
        ]);

        $user->password = Hash::make($request->input('new_password'));
        $user->save();

        return redirect()->route('profile')
            ->with('success', 'Password updated successfully.');
    }

    /**
     * Strip everything but digits, then coerce empty to null so that
     * `required_without:passport_number` correctly treats a whitespace-only
     * value as "not supplied". Also handles the non-breaking space (U+00A0)
     * that iOS autocorrect and some CSV imports insert.
     */
    private static function normaliseSaIdNumber(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $digits = preg_replace('/\D+/u', '', $value) ?? '';

        return $digits === '' ? null : $digits;
    }

    /**
     * Trim the passport number and coerce empty string to null; the
     * `required_without:sa_id_number` rule requires that pairing to be
     * symmetric.
     */
    private static function normalisePassportNumber(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
