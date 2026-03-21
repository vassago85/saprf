<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('matches', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable()->unique();
            $table->string('match_type');
            $table->string('series_level');
            $table->string('series')->nullable();
            $table->string('season')->nullable();
            $table->foreignId('province_id')->nullable()->constrained('provinces')->nullOnDelete();
            $table->string('venue_name')->nullable();
            $table->string('venue_location')->nullable();
            $table->text('description')->nullable();
            $table->date('match_date');
            $table->date('registration_open_date')->nullable();
            $table->date('registration_close_date')->nullable();
            $table->string('status')->default('draft');
            $table->foreignId('created_by')->constrained('users')->cascadeOnUpdate()->restrictOnDelete();
            $table->decimal('active_member_fee', 10, 2)->default(0);
            $table->decimal('non_member_fee', 10, 2)->default(0);
            $table->decimal('lapsed_member_fee', 10, 2)->default(0);
            $table->boolean('category_rankings_enabled')->default(false);
            $table->boolean('division_awards_enabled')->default(false);
            $table->boolean('category_awards_enabled')->default(false);
            $table->timestamps();

            $table->index(['match_type', 'series_level', 'season']);
            $table->index(['created_by', 'status']);
            $table->index('match_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('matches');
    }
};
