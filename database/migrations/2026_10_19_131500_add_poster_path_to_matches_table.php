<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Optional full promotional poster (vertical flyer) separate from the
     * wide listing banner. Stored on the public disk under match-posters/.
     */
    public function up(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            if (Schema::hasColumn('matches', 'banner_path')) {
                $table->string('poster_path')->nullable()->after('banner_path');
            } else {
                $table->string('poster_path')->nullable()->after('description');
            }
        });
    }

    public function down(): void
    {
        Schema::table('matches', function (Blueprint $table) {
            $table->dropColumn('poster_path');
        });
    }
};
