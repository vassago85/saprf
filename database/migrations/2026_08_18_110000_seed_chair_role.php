<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    /**
     * The `chair` role sits on top of `exco`: chairs get everything Exco can
     * do, plus a handful of exclusive privileges (send Policy change
     * announcements unilaterally, soft-delete records from the Exco vault
     * when that ships). Assignment always unions `exco` in
     * UserManagementController, so a chair without exco should not exist.
     */
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        Role::firstOrCreate(['name' => 'chair', 'guard_name' => 'web']);
    }

    public function down(): void
    {
        Role::where('name', 'chair')->where('guard_name', 'web')->delete();

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
