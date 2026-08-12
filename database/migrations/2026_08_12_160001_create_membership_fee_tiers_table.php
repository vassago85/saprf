<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('membership_fee_tiers', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            // Application and renewal are charged at the same amount per tier.
            $table->decimal('price', 8, 2)->default(0);
            $table->unsignedSmallInteger('duration_months')->default(12);
            $table->unsignedInteger('display_order')->default(0);
            $table->boolean('is_active')->default(true);
            // The tier used as the default price and pre-selected on the join page.
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['is_active', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('membership_fee_tiers');
    }
};
