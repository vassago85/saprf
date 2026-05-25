<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    public function up(): void
    {
        Role::firstOrCreate(['name' => 'developer']);
    }

    public function down(): void
    {
        Role::where('name', 'developer')->delete();
    }
};
