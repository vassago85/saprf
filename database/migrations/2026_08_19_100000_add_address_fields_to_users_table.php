<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'address_line_1')) {
                $table->string('address_line_1')->nullable();
            }
            if (! Schema::hasColumn('users', 'address_line_2')) {
                $table->string('address_line_2')->nullable();
            }
            if (! Schema::hasColumn('users', 'address_line_3')) {
                $table->string('address_line_3')->nullable();
            }
            if (! Schema::hasColumn('users', 'city')) {
                $table->string('city', 120)->nullable();
            }
            if (! Schema::hasColumn('users', 'postal_code')) {
                $table->string('postal_code', 20)->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            foreach (['address_line_1', 'address_line_2', 'address_line_3', 'city', 'postal_code'] as $column) {
                if (Schema::hasColumn('users', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
