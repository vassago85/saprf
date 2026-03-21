<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('season_classification_categories', function (Blueprint $table) {
            $table->foreignId('classification_id')->constrained('season_shooter_classifications')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();

            $table->primary(['classification_id', 'category_id'], 'season_class_cat_primary');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('season_classification_categories');
    }
};
