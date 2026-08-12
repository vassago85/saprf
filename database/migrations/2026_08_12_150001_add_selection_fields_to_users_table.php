<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'sa_citizen')) {
                $table->boolean('sa_citizen')->nullable()->after('sa_id_number');
            }
            if (! Schema::hasColumn('users', 'country_of_residence')) {
                $table->string('country_of_residence', 2)->nullable()->after('sa_citizen');
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (Schema::hasColumn('users', 'country_of_residence')) {
                $table->dropColumn('country_of_residence');
            }
            if (Schema::hasColumn('users', 'sa_citizen')) {
                $table->dropColumn('sa_citizen');
            }
        });
    }
};
