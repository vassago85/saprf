<?php

namespace App\Http\Controllers;

use App\Enums\AnnouncementCategory;
use App\Models\NotificationPreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Small, self-contained controller for the per-member "which emails do
 * you want" checkboxes and the per-device push toggle. Kept separate
 * from ProfileController on purpose — the profile form already juggles
 * SASCOC + selection + password + club fields, and adding notification
 * prefs would balloon its validation surface.
 *
 * Mandatory categories (Policy change, Urgent) never appear in the mute
 * UI — they are enforced by the enum itself and by the send job — so a
 * carefully crafted POST cannot silence them.
 */
class NotificationPreferencesController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        $user = $request->user();

        $mutable = collect(AnnouncementCategory::cases())
            ->reject(fn (AnnouncementCategory $c) => $c->isMandatory())
            ->pluck('value')
            ->all();

        $validated = $request->validate([
            'muted_categories' => ['array'],
            'muted_categories.*' => ['string', 'in:' . implode(',', $mutable)],
            'push_enabled' => ['nullable', 'boolean'],
        ]);

        $pref = NotificationPreference::firstOrNew(['user_id' => $user->id]);
        $pref->fill([
            'user_id' => $user->id,
            'muted_email_categories' => array_values($validated['muted_categories'] ?? []),
            'push_enabled' => (bool) ($validated['push_enabled'] ?? false),
        ]);
        $pref->save();

        return redirect()->route('profile')
            ->with('success', 'Notification preferences saved.');
    }
}
