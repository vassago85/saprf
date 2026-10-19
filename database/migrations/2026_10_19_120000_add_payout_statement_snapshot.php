<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Frozen MD-payout statements.
 *
 * Extends payout_items with the extra per-entry context the statement
 * needs (shooter name, fee category, surcharge, payment method, excluded
 * flag) so a statement rendered today reads the exact numbers that were
 * approved at payout time, not whatever the live registrations currently
 * hold. Also adds snapshot bookkeeping columns on `payouts` so the
 * summary totals, match header, and generation timestamp survive later
 * refunds or category corrections.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payout_items', function (Blueprint $table): void {
            $table->foreignId('match_registration_id')
                ->nullable()
                ->after('source_id')
                ->constrained('match_registrations')
                ->nullOnDelete();
            $table->string('shooter_name')->nullable()->after('description');
            $table->string('membership_fee_category', 30)->nullable()->after('shooter_name');
            $table->string('payment_status', 20)->nullable()->after('membership_fee_category');
            $table->string('payment_method', 30)->nullable()->after('payment_status');
            $table->decimal('surcharge_amount', 10, 2)->default(0)->after('gross_amount');
            $table->boolean('excluded')->default(false)->after('net_amount');
            $table->string('excluded_reason', 50)->nullable()->after('excluded');
            $table->unsignedInteger('sort_order')->default(0)->after('excluded_reason');

            $table->index(['payout_id', 'excluded']);
        });

        Schema::table('payouts', function (Blueprint $table): void {
            $table->dateTime('statement_generated_at')->nullable()->after('paid_at');
            $table->unsignedInteger('statement_version')->default(1)->after('statement_generated_at');
            $table->json('statement_snapshot')->nullable()->after('statement_version');
        });
    }

    public function down(): void
    {
        Schema::table('payouts', function (Blueprint $table): void {
            $table->dropColumn(['statement_generated_at', 'statement_version', 'statement_snapshot']);
        });

        Schema::table('payout_items', function (Blueprint $table): void {
            $table->dropForeign(['match_registration_id']);
            $table->dropIndex(['payout_id', 'excluded']);
            $table->dropColumn([
                'match_registration_id',
                'shooter_name',
                'membership_fee_category',
                'payment_status',
                'payment_method',
                'surcharge_amount',
                'excluded',
                'excluded_reason',
                'sort_order',
            ]);
        });
    }
};
