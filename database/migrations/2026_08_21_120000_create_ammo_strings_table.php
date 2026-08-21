<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ammo_strings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            // Both links are optional at the DB level — an early string may be
            // recorded before the shooter picks their ammo load, and some
            // shooters share a barrel record only after firing. In practice
            // the UI insists on picking a load; barrel remains truly optional.
            $table->foreignId('ammo_load_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('barrel_id')->nullable()->constrained()->nullOnDelete();

            // Optional link back to the parent ladder session — "this string
            // confirms load X from ladder Y." Powers the "confirmed at X fps"
            // badge on the ladder page.
            $table->foreignId('ladder_session_id')->nullable()->constrained()->nullOnDelete();

            $table->string('label', 120);
            $table->date('fired_on')->nullable();
            $table->decimal('temperature_c', 4, 1)->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['user_id', 'fired_on']);
            $table->index('ammo_load_id');
            $table->index('barrel_id');
            $table->index('ladder_session_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ammo_strings');
    }
};
