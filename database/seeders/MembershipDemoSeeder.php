<?php

namespace Database\Seeders;

use App\Models\Membership;
use App\Models\Province;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Idempotent seeder for demo memberships.
 *
 * Creates a small set of demo users with active memberships so the Memberships
 * index and Provincial Members listings have content. Designed to be safe on
 * prod re-runs — keys on a stable email/saprf_number rather than the brittle
 * counter pattern in FederationDemoSeeder.
 */
class MembershipDemoSeeder extends Seeder
{
    public function run(): void
    {
        $provinces = Province::all()->keyBy('abbreviation');
        if ($provinces->isEmpty()) {
            $this->command?->warn('No provinces found — run ProvinceSeeder first.');
            return;
        }

        // 12 demo shooters across 4 provinces. saprf_number range 0501-0512 starts
        // well above the FederationDemoSeeder's 1001+ counter to avoid collisions
        // if that seeder is ever run later.
        $shooters = [
            ['name' => 'Andries Pretorius',  'prov' => 'GP'],
            ['name' => 'Bianca Joubert',     'prov' => 'GP'],
            ['name' => 'Cobus Steyn',        'prov' => 'GP'],
            ['name' => 'Danielle Botha',     'prov' => 'WC'],
            ['name' => 'Eduan le Roux',      'prov' => 'WC'],
            ['name' => 'Frans Coetzee',      'prov' => 'WC'],
            ['name' => 'Greta van Tonder',   'prov' => 'KZN'],
            ['name' => 'Hennie Marais',      'prov' => 'KZN'],
            ['name' => 'Ilse de Wet',        'prov' => 'KZN'],
            ['name' => 'Jaco Smith',         'prov' => 'FS'],
            ['name' => 'Karien Olivier',     'prov' => 'FS'],
            ['name' => 'Lourens Bekker',     'prov' => 'FS'],
        ];

        $created = 0;
        $existing = 0;

        foreach ($shooters as $i => $data) {
            $province = $provinces[$data['prov']] ?? $provinces->first();
            $saprfNumber = 'SAPRF-2026-' . str_pad((string) (501 + $i), 4, '0', STR_PAD_LEFT);
            $email = 'demo-' . strtolower(str_replace(' ', '-', $data['name'])) . '@saprf.co.za';

            $user = User::firstOrCreate(
                ['email' => $email],
                [
                    'name' => $data['name'],
                    'password' => Hash::make('password'),
                    'province_id' => $province->id,
                    'email_verified_at' => now(),
                    'must_change_password' => false,
                ],
            );

            $user->assignRole('member');

            $membership = Membership::firstOrCreate(
                ['saprf_number' => $saprfNumber],
                [
                    'user_id' => $user->id,
                    'membership_type' => 'paid',
                    'status' => 'active',
                    'payment_status' => 'paid',
                    'start_date' => now()->subMonths(rand(1, 8))->toDateString(),
                    'expiry_date' => now()->addMonths(rand(3, 11))->toDateString(),
                ],
            );

            $membership->wasRecentlyCreated ? $created++ : $existing++;
        }

        $this->command?->info("MembershipDemoSeeder: {$created} created, {$existing} already present.");
    }
}
