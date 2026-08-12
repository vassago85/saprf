<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the SASCOC demographic reporting fields (gender, ethnicity,
 * previously-disadvantaged flag), an optional passport number as an
 * alternative to sa_id_number for non-SA citizens, and a Mil/LE number
 * carried over from the legacy PRS platform. All fields are nullable so
 * pre-existing users are not forced into a schema migration and can be
 * back-filled progressively via the profile-complete nag.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('gender', 20)->nullable()->after('date_of_birth');
            $table->string('ethnicity', 30)->nullable()->after('gender');
            $table->boolean('previously_disadvantaged')->nullable()->after('ethnicity');
            $table->string('passport_number', 50)->nullable()->after('sa_id_number');
            $table->string('mil_le_number', 50)->nullable()->after('passport_number');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'gender',
                'ethnicity',
                'previously_disadvantaged',
                'passport_number',
                'mil_le_number',
            ]);
        });
    }
};
