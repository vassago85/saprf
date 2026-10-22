<?php

namespace App\Support;

use App\Actions\LinkFreeDependant;
use App\Models\User;

/**
 * One-off correction for Theo Kriel and his son Juan.
 *
 * Theo's login was the import placeholder. Juan was registered on
 * cernoflash@gmail.com because the two accounts could not share an
 * email. Theo asked for nuformconstruction.1@gmail.com on his own
 * account, with Juan kept as his junior dependant. Both stay free
 * non-members. No-op when those accounts are not in this database.
 */
class KrielAccountsRepair
{
    public const GUARDIAN_EMAIL = 'nuformconstruction.1@gmail.com';

    public function apply(): bool
    {
        $theo = User::query()->where('email', 'theo.kriel@import.saprf.local')->first()
            ?? User::query()->where('email', self::GUARDIAN_EMAIL)->first()
            ?? User::query()
                ->where('name', 'Theo Kriel')
                ->whereHas('membership', fn ($query) => $query->where('saprf_number', '1604'))
                ->first();

        $juan = User::query()->whereRaw('lower(email) = ?', ['cernoflash@gmail.com'])->first()
            ?? User::query()
                ->where('name', 'Juan Kriel')
                ->whereHas('membership', fn ($query) => $query->where('saprf_number', '1990'))
                ->first();

        if (! $theo || ! $juan || $theo->id === $juan->id) {
            return false;
        }

        $taken = User::query()
            ->whereRaw('lower(email) = ?', [self::GUARDIAN_EMAIL])
            ->where('id', '!=', $theo->id)
            ->exists();

        if (! $taken && strcasecmp((string) $theo->email, self::GUARDIAN_EMAIL) !== 0) {
            $theo->email = self::GUARDIAN_EMAIL;
            $theo->save();
        }

        app(LinkFreeDependant::class)->link($theo, $juan, 'junior');

        return true;
    }
}
