<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('optic_makes', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('country')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('user_submitted')->default(false);
            $table->timestamps();
        });

        Schema::create('optic_models', function (Blueprint $table) {
            $table->id();
            $table->foreignId('optic_make_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->boolean('user_submitted')->default(false);
            $table->timestamps();

            $table->unique(['optic_make_id', 'name']);
        });

        Schema::table('rifle_configurations', function (Blueprint $table) {
            $table->foreignId('optic_make_id')->nullable()->after('chassis_description')->constrained()->nullOnDelete();
            $table->foreignId('optic_model_id')->nullable()->after('optic_make_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('rifle_configurations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('optic_model_id');
            $table->dropConstrainedForeignId('optic_make_id');
        });

        Schema::dropIfExists('optic_models');
        Schema::dropIfExists('optic_makes');
    }
};
