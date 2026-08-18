<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per targeting rule attached to an announcement. Composed
     * via include/exclude to describe things like "All active members,
     * EXCLUDING Exco" — that's two rows on the same announcement.
     *
     * `value` payload varies by `type`:
     *   membership_type → {"membership_type":"paid"}
     *   fee_tier        → {"fee_tier_id":3}
     *   division        → {"division_id":2}
     *   series          → {"series":"PRS","season":"2026"}
     *   role            → {"role":"match_director"}
     *   club            → {"club_id":12}
     *   province        → {"province_id":4}
     *   individual      → {"user_ids":[12,44,99]}
     *   saved_list      → {"list_id":7}
     *   all / active_members → {} (empty object)
     */
    public function up(): void
    {
        Schema::create('announcement_audiences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('announcement_id')->constrained()->cascadeOnDelete();
            $table->string('type', 40);
            $table->json('value');
            $table->string('mode', 10)->default('include');
            $table->timestamps();

            $table->index(['announcement_id', 'mode']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('announcement_audiences');
    }
};
