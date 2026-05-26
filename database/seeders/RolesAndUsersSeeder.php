<?php

namespace Database\Seeders;

use App\Models\Province;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class RolesAndUsersSeeder extends Seeder
{
    public function run(): void
    {
        $roles = ['developer', 'owner', 'admin', 'match_director', 'member', 'provincial_admin'];

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
        ];

        foreach ($users as $data) {
            $user = User::firstOrCreate(
                ['email' => $data['email']],
                [
                    'name' => $data['name'],
                    'password' => Hash::make('password'),
                    'province_id' => $data['province_id'],
                    'email_verified_at' => now(),
                    'must_change_password' => true,
                ],
            );

            if ($user->province_id !== $data['province_id']) {
                $user->update(['province_id' => $data['province_id']]);
            }

            $user->syncRoles($data['roles']);
        }
    }
}
