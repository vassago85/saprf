<?php

namespace App\Services;

use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Find-or-create a stub shooter account when a sponsor or match director
 * enters someone who isn't on the platform yet.
 *
 * The returned user is a minimal, unclaimed account: random password (so no
 * one can guess in and log in as them), no verified email, and a
 * `membership_type = 'free'` membership so pricing treats them as a
 * non-member. If a real email was supplied, they can later claim the
 * account through the standard forgot-password flow; the same email in a
 * future entry will resolve back to the same user, so we never book two
 * separate stubs for the same person.
 *
 * Never touches or mutates an existing REAL member matched by name alone —
 * only stubs are considered for name-based dedup, because two different
 * people can plausibly share a name and we would rather force a search hit
 * than silently attach a sponsored entry to the wrong person.
 */
class GuestShooterService
{
    public const PLACEHOLDER_EMAIL_DOMAIN = 'import.saprf.local';

    /**
     * Resolve the shooter for a sponsored/seeded entry, creating a new stub
     * only when nothing plausible already exists.
     *
     * Dedup order (first hit wins):
     *   1. Case-insensitive exact match on `users.email` (the strongest ID —
     *      if the sponsor typed a real email that already belongs to
     *      someone on the platform, that IS the shooter).
     *   2. Case-insensitive exact match on `users.name` against an existing
     *      STUB account (placeholder @import.saprf.local email). Real
     *      accounts sharing the name are left alone.
     *
     * If neither hits, a fresh stub is created with:
     *   - the supplied real email, or a `first.last@import.saprf.local`
     *     placeholder (uniqued by suffix if it collides);
     *   - a random password (so nobody can log in until they reset);
     *   - `is_active = true`, `is_managed_account = false`,
     *     `email_verified_at = null`;
     *   - a paired free-type Membership with a `SAPRF-IMPORT-XXXXXX`
     *     legacy-style number, so the pricing service classifies them as
     *     a non-member;
     *   - the `member` role.
     */
    public function findOrCreate(string $name, ?string $email = null): User
    {
        $name = $this->normaliseName($name);
        if ($name === '') {
            throw new \InvalidArgumentException('Shooter name is required.');
        }

        $email = $this->normaliseEmail($email);

        if ($email !== null) {
            $viaEmail = User::whereRaw('LOWER(email) = ?', [$email])->first();
            if ($viaEmail) {
                return $viaEmail;
            }
        }

        $viaName = User::whereRaw('LOWER(name) = ?', [strtolower($name)])->first();
        if ($viaName && $this->isStub($viaName)) {
            // If the caller has now supplied a real email for a stub that
            // previously only had a placeholder, upgrade the record so
            // future sponsors reach the same person via the stronger
            // email-based dedup path.
            if ($email !== null && $this->hasPlaceholderEmail($viaName)) {
                $viaName->forceFill(['email' => $email])->save();
            }

            return $viaName;
        }

        return $this->createStub($name, $email);
    }

    private function createStub(string $name, ?string $email): User
    {
        $storeEmail = $email ?? $this->placeholderEmailFor($name);
        $storeEmail = $this->uniqueEmail($storeEmail);

        $user = User::create([
            'name' => $name,
            'email' => $storeEmail,
            // Random unknowable password: the account is "unclaimed" — they
            // must go through Forgot Password (if they have a real email
            // on file) to actually log in. `null` isn't safe here because
            // Auth::attempt() would hash the empty string and match.
            'password' => Hash::make(Str::random(64)),
            'is_active' => true,
            'is_managed_account' => false,
            'email_verified_at' => null,
            'must_change_password' => false,
        ]);
        $user->assignRole('member');

        // Legacy-style import number so the placeholder never collides with
        // the real sequential SAPRF numbering scheme. Pricing sees this as
        // a non-member which is exactly what an unclaimed stub should pay.
        Membership::firstOrCreate(
            ['user_id' => $user->id],
            [
                'saprf_number' => $this->uniqueImportSaprfNumber(),
                'membership_type' => 'free',
                'status' => 'pending',
                'payment_status' => 'unpaid',
            ],
        );

        return $user;
    }

    private function isStub(User $user): bool
    {
        return $this->hasPlaceholderEmail($user);
    }

    private function hasPlaceholderEmail(User $user): bool
    {
        return str_ends_with(strtolower((string) $user->email), '@' . self::PLACEHOLDER_EMAIL_DOMAIN);
    }

    private function normaliseName(string $name): string
    {
        return trim(preg_replace('/\s+/', ' ', $name));
    }

    private function normaliseEmail(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $email = trim(strtolower($email));
        if ($email === '') {
            return null;
        }

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException("Invalid email address: {$email}");
        }

        return $email;
    }

    private function placeholderEmailFor(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name));
        $first = Str::slug($parts[0] ?? 'shooter') ?: 'shooter';
        $last = count($parts) > 1 ? Str::slug((string) end($parts)) : '';

        return $first . ($last ? '.' . $last : '') . '@' . self::PLACEHOLDER_EMAIL_DOMAIN;
    }

    private function uniqueEmail(string $email): string
    {
        if (! User::where('email', $email)->exists()) {
            return $email;
        }

        // Name collisions on the placeholder scheme are the main case:
        // john.doe@import.saprf.local, john.doe2@import.saprf.local, …
        [$local, $domain] = explode('@', $email, 2);
        $n = 2;
        do {
            $candidate = $local . $n . '@' . $domain;
            $n++;
        } while (User::where('email', $candidate)->exists());

        return $candidate;
    }

    private function uniqueImportSaprfNumber(): string
    {
        do {
            $candidate = 'SAPRF-IMPORT-' . strtoupper(Str::random(6));
        } while (Membership::where('saprf_number', $candidate)->exists());

        return $candidate;
    }
}
