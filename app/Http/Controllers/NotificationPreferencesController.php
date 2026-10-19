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
            'notify_own_match_scores' => ['nullable', 'boolean'],
            'notify_all_prs_scores' => ['nullable', 'boolean'],
            'notify_all_pr22_scores' => ['nullable', 'boolean'],
        ]);

        $pref = NotificationPreference::firstOrNew(['user_id' => $user->id]);
        $pref->fill([
            'user_id' => $user->id,
            'muted_email_categories' => array_values($validated['muted_categories'] ?? []),
            'push_enabled' => (bool) ($validated['push_enabled'] ?? false),
            'notify_own_match_scores' => (bool) ($validated['notify_own_match_scores'] ?? false),
            'notify_all_prs_scores' => (bool) ($validated['notify_all_prs_scores'] ?? false),
            'notify_all_pr22_scores' => (bool) ($validated['notify_all_pr22_scores'] ?? false),
            // Saving the form is itself an explicit opt-in to a choice,
            // so the one-time prompt stops nagging — even if they left
            // every toggle at its default.
            'score_prefs_configured_at' => $pref->score_prefs_configured_at ?? now(),
        ]);
        $pref->save();

        return redirect()->route('profile')
            ->with('success', 'Notification preferences saved.');
    }

    /**
     * Lightweight endpoint for the one-time post-login prompt — accepts
     * the three score toggles only, then stamps `score_prefs_configured_at`
     * so the banner stops appearing. We intentionally don't touch mute
     * categories or push_enabled here: those live in the full profile
     * form so the prompt stays focused on the one choice we're actually
     * asking for.
     */
    public function savePrompt(Request $request): RedirectResponse
    {
        $user = $request->user();

        $validated = $request->validate([
            'notify_own_match_scores' => ['nullable', 'boolean'],
            'notify_all_prs_scores' => ['nullable', 'boolean'],
            'notify_all_pr22_scores' => ['nullable', 'boolean'],
        ]);

        $pref = NotificationPreference::firstOrNew(['user_id' => $user->id]);
        $pref->fill([
            'user_id' => $user->id,
            'notify_own_match_scores' => (bool) ($validated['notify_own_match_scores'] ?? false),
            'notify_all_prs_scores' => (bool) ($validated['notify_all_prs_scores'] ?? false),
            'notify_all_pr22_scores' => (bool) ($validated['notify_all_pr22_scores'] ?? false),
            'score_prefs_configured_at' => now(),
        ]);
        $pref->save();

        return redirect()->back()
            ->with('success', 'Score notification preferences saved. You can change these anytime in your Notification Preferences.');
    }
}
