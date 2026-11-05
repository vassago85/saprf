<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A person running the federation colours report can award a grade to a
 * shooter who did not compete at the SA Championships, provided they record
 * a reason. One override per shooter, series, season, and division.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('federation_colour_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('series', 20);
            $table->string('season', 10);
            $table->foreignId('division_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(
                ['user_id', 'series', 'season', 'division_id'],
                'federation_colour_overrides_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('federation_colour_overrides');
    }
};
