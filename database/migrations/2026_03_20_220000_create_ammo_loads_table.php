<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ammo_loads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('nickname', 100);
            $table->foreignId('firearm_calibre_id')->nullable()->constrained()->nullOnDelete();
            $table->string('bullet_make', 100)->nullable();
            $table->string('bullet_model', 100)->nullable();
            $table->string('bullet_weight', 30)->nullable();
            $table->string('bullet_type', 100)->nullable();
            $table->string('brass', 100)->nullable();
            $table->string('primer', 100)->nullable();
            $table->string('powder', 100)->nullable();
            $table->string('charge_weight', 30)->nullable();
            $table->string('coal', 30)->nullable();
            $table->string('cbto', 30)->nullable();
            $table->string('velocity', 30)->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['user_id', 'is_active']);
            $table->index('firearm_calibre_id');
        });

        Schema::table('match_registrations', function (Blueprint $table) {
            $table->foreignId('ammo_load_id')->nullable()->after('rifle_configuration_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('match_registrations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ammo_load_id');
        });

        Schema::dropIfExists('ammo_loads');
    }
};
