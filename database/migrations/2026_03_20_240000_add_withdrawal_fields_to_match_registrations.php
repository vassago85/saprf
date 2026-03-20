<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_registrations', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('registered_at');
            $table->decimal('refund_amount', 10, 2)->nullable()->after('fee_override_reason');
            $table->decimal('admin_fee_charged', 10, 2)->nullable()->after('refund_amount');
            $table->string('cancellation_reason', 500)->nullable()->after('admin_fee_charged');
        });
    }

    public function down(): void
    {
        Schema::table('match_registrations', function (Blueprint $table) {
            $table->dropColumn(['cancelled_at', 'refund_amount', 'admin_fee_charged', 'cancellation_reason']);
        });
    }
};
