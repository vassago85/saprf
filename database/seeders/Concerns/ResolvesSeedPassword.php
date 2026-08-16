<?php

namespace Database\Seeders\Concerns;

use Illuminate\Support\Str;

/**
 * Seeded accounts must never carry a password that is committed to the repo —
 * that would be a published credential for every environment the seeder has
 * ever run against, including production.
 *
 * Resolution order:
 *   1. SEED_PASSWORD_<LOCAL_PART>  — one specific account (e.g. SEED_PASSWORD_EXCO)
 *   2. SEED_DEFAULT_PASSWORD       — every seeded account (set this for local dev)
 *   3. a random 20-character password, announced once for staff accounts and
 *      simply discarded for demo fixtures
 */
trait ResolvesSeedPassword
{
    protected function seedPassword(string $email): string
    {
        return $this->configuredSeedPassword($email) ?? Str::password(20);
    }

    protected function configuredSeedPassword(string $email): ?string
    {
        $specific = env('SEED_PASSWORD_'.Str::upper(Str::before($email, '@')));

        if (is_string($specific) && $specific !== '') {
            return $specific;
        }

        $default = env('SEED_DEFAULT_PASSWORD');

        return is_string($default) && $default !== '' ? $default : null;
    }
}
