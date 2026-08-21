<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ladder_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ladder_session_id')->constrained()->cascadeOnDelete();
            // value is charge (gr) or seating measurement (mm) per the session's variable
            $table->decimal('value', 6, 3);
            $table->boolean('include_in_fit')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->unique(['ladder_session_id', 'value']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ladder_steps');
    }
};
