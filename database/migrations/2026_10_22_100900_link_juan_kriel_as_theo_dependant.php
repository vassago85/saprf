<?php

use App\Support\KrielAccountsRepair;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        app(KrielAccountsRepair::class)->apply();
    }

    public function down(): void
    {
        // The previous emails and the separate logins are not restored.
        // Unlink from User Management if this link needs to be undone.
    }
};
