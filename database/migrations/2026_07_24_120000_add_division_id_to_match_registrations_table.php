<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_registrations', function (Blueprint $table) {
            // The division (equipment class) the shooter enters under. Nullable
            // so historical entries made before this field remain valid.
            $table->foreignId('division_id')->nullable()->after('rifle_configuration_id')
                ->constrained('divisions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('match_registrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('division_id');
        });
    }
};
