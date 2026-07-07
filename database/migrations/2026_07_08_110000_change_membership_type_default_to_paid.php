<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The memberships table originally defaulted membership_type to "free". Now that
 * "free" specifically means a non-member (someone forced to register to shoot a
 * single provincial), a membership created without an explicit type should
 * represent a normal paying member — so flip the default to "paid".
 *
 * Existing rows are untouched; every real creation path already sets the type
 * explicitly, so this only guards against future rows accidentally inheriting a
 * non-member default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->string('membership_type')->default('paid')->change();
        });
    }

    public function down(): void
    {
        Schema::table('memberships', function (Blueprint $table) {
            $table->string('membership_type')->default('free')->change();
        });
    }
};
