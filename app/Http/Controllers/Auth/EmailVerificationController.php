<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Handles the signed "Verify Email" link from the verification email.
 *
 * Intentionally unauthenticated: the signature + email hash prove ownership,
 * so the link works on any device — not only the browser that registered.
 */
class EmailVerificationController extends Controller
{
    public function __invoke(Request $request, int $id, string $hash): RedirectResponse
    {
        $user = User::query()->findOrFail($id);

        if (! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            abort(403, 'Invalid verification link.');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->forceFill([
                'email_verified_at' => now(),
                'email_otp' => null,
                'email_otp_expires_at' => null,
            ])->save();

            event(new Verified($user));
        }

        // Log them in on this device so they can continue immediately.
        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'))
            ->with('success', 'Your email has been verified.');
    }
}
