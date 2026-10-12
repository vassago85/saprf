<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('match_registrations', function (Blueprint $table) {
            // credit | refund — what the member chose when withdrawing a paid entry.
            $table->string('withdrawal_option', 20)->nullable()->after('cancellation_reason');
            // Split of the withdrawal fee kept on a cash refund (developer, PayFast, SAPRF).
            $table->decimal('penalty_platform_fee', 10, 2)->default(0)->after('withdrawal_option');
            $table->decimal('penalty_gateway_fee', 10, 2)->default(0)->after('penalty_platform_fee');
            $table->decimal('penalty_saprf_fee', 10, 2)->default(0)->after('penalty_gateway_fee');
        });

        Schema::table('account_credits', function (Blueprint $table) {
            // Withdrawal credit: PayFast fee already paid on the original entry.
            // Redemption: share of that fee carried onto the entry it pays for.
            $table->decimal('gateway_fee', 10, 2)->default(0)->after('amount');
            // Redemption: how much of the amount was drawn from withdrawal credit.
            $table->decimal('withdrawal_credit_amount', 10, 2)->default(0)->after('gateway_fee');
        });

        Schema::table('credit_refund_requests', function (Blueprint $table) {
            $table->foreignId('match_registration_id')->nullable()->after('account_credit_id')
                ->constrained('match_registrations')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('credit_refund_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('match_registration_id');
        });

        Schema::table('account_credits', function (Blueprint $table) {
            $table->dropColumn(['gateway_fee', 'withdrawal_credit_amount']);
        });

        Schema::table('match_registrations', function (Blueprint $table) {
            $table->dropColumn(['withdrawal_option', 'penalty_platform_fee', 'penalty_gateway_fee', 'penalty_saprf_fee']);
        });
    }
};
