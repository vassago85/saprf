<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('standings', function (Blueprint $table) {
            $table->decimal('points', 10, 4)->default(0)->change();
        });
    }

    public function down(): void
    {
        Schema::table('standings', function (Blueprint $table) {
            $table->decimal('points', 10, 3)->default(0)->change();
        });
    }
};
