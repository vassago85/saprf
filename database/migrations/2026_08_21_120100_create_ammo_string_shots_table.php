<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ammo_string_shots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ammo_string_id')->constrained()->cascadeOnDelete();

            // 1-indexed shot number in fire order. This is the axis the
            // string analyser lives on — cold-bore, thermal drift, autocorr,
            // everything trades on shot ordinality. Keeping it explicit
            // means shooters can reorder rows in the UI without losing "was
            // this fired first?" information.
            $table->unsignedSmallInteger('sequence');
            $table->decimal('velocity_fps', 6, 1);

            // Excluded shots stay in the row list so the shooter can toggle
            // them back on (a chrono error is not always obvious in the
            // moment). Analysis math strips them; the UI renders them
            // struck-through, mirroring the ladder behaviour.
            $table->boolean('excluded')->default(false);
            $table->string('notes', 300)->nullable();

            $table->timestamps();

            $table->unique(['ammo_string_id', 'sequence']);
            $table->index('ammo_string_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ammo_string_shots');
    }
};
