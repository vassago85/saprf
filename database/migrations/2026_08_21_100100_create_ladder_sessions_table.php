<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ladder_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('barrel_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ammo_load_id')->nullable()->constrained()->nullOnDelete();
            // match_id is present so a load can be tied to a match later; there is
            // no UI in this task — the FK exists so we do not have to migrate
            // existing rows when the load-to-match linking feature ships.
            $table->foreignId('match_id')->nullable()->constrained('matches')->nullOnDelete();

            // Variable enum drives the unit and axis label. Kept as a string
            // column so the schema is easy to extend for future variables
            // (case volume, neck tension) without a migration on every one.
            $table->string('variable', 30)->default('charge_weight');
            $table->string('unit', 4);

            $table->string('name', 120);
            $table->date('fired_on');
            $table->text('notes')->nullable();

            // Snapshot the barrel round count at time of firing so the reading
            // stays meaningful long after the barrel's count has moved on.
            $table->unsignedInteger('barrel_round_count_at_session')->nullable();
            $table->decimal('temperature_c', 4, 1)->nullable();

            $table->string('powder', 100)->nullable();
            $table->string('bullet', 100)->nullable();
            $table->string('brass', 100)->nullable();
            $table->string('primer', 100)->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'fired_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ladder_sessions');
    }
};
