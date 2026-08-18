<?php

namespace Database\Seeders;

use App\Models\Province;
use App\Models\User;
use Database\Seeders\Concerns\ResolvesSeedPassword;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class RolesAndUsersSeeder extends Seeder
{
    use ResolvesSeedPassword;

    public function run(): void
    {
        $roles = ['developer', 'exco', 'chair', 'owner', 'admin', 'match_director', 'member', 'provincial_admin', 'iprf_selector'];

        foreach ($roles as $role) {
            Role::firstOrCreate(['name' => $role]);
        }

        $gp = Province::where('abbreviation', 'GP')->first();
        $wc = Province::where('abbreviation', 'WC')->first();
        $fs = Province::where('abbreviation', 'FS')->first();

        $users = [
            ['name' => 'SAPRF Developer', 'email' => 'developer@saprf.co.za', 'roles' => ['developer', 'member'], 'province_id' => $gp?->id],
            ['name' => 'SAPRF Owner', 'email' => 'owner@saprf.co.za', 'roles' => ['owner', 'member'], 'province_id' => $gp?->id],
            ['name' => 'SAPRF Admin', 'email' => 'admin@saprf.co.za', 'roles' => ['admin', 'member'], 'province_id' => $gp?->id],
            ['name' => 'Match Director', 'email' => 'director@saprf.co.za', 'roles' => ['match_director', 'member'], 'province_id' => $wc?->id],
            ['name' => 'Provincial Admin', 'email' => 'provincial@saprf.co.za', 'roles' => ['provincial_admin', 'member'], 'province_id' => $gp?->id],
            ['name' => 'Active Member', 'email' => 'member@saprf.co.za', 'roles' => ['member'], 'province_id' => $fs?->id],
            // Shared EXCO walkthrough account. Force-reset is off so the board's
            // first click works, which means its password must never be a value
            // committed to the repo — see resolvePassword().
            ['name' => 'SAPRF Exco', 'email' => 'exco@saprf.co.za', 'roles' => ['exco', 'member'], 'province_id' => $gp?->id, 'must_change_password' => false],
        ];

        foreach ($users as $data) {
            $password = $this->seedPassword($data['email']);

            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make($password),
                    'province_id' => $data['province_id'],
                    'email_verified_at' => now(),
                    'must_change_password' => $data['must_change_password'] ?? true,
                ],
            );

            if ($user->wasRecentlyCreated) {
                $this->announce($data['email'], $password);
            }

            if ($user->province_id !== $data['province_id']) {
                $user->update(['province_id' => $data['province_id']]);
            }

            $user->syncRoles($data['roles']);
        }
    }

    /**
     * Print a generated password once. Skipped when the operator supplied one
     * through the environment — they already know it.
     */
    private function announce(string $email, string $password): void
    {
        if ($this->configuredSeedPassword($email) !== null) {
            return;
        }

        $this->command?->warn("Generated password for {$email}: {$password}");
        $this->command?->warn('Store it now — it is not recoverable and will not be shown again.');
    }
}
