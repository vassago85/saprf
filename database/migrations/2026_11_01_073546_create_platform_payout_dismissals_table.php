<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets an operator mark a past month's platform-fee settlement as handled
 * outside the payout flow (e.g. invoiced via Charsley). Without this,
 * `PlatformPayoutService::unsettledMonths()` keeps surfacing the month on
 * the finance dashboard forever because no `Payout` row exists for it.
 *
 * One dismissal per month (period_start+period_end). Payouts are still
 * the primary settlement record — this table is only for months the
 * operator consciously decided NOT to pay out through the platform.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_payout_dismissals', function (Blueprint $table) {
            $table->id();
            $table->date('period_start');
            $table->date('period_end');
            // Snapshot the figures at dismissal time so later auditing shows
            // what was outstanding when the operator cleared it, even if
            // registrations are later refunded / recategorised.
            $table->decimal('platform_fees_amount', 12, 2)->default(0);
            $table->unsignedInteger('entry_count')->default(0);
            $table->string('reason')->default('Invoiced via Charsley');
            $table->foreignId('dismissed_by')->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('dismissed_at')->useCurrent();
            $table->timestamps();

            $table->unique(['period_start', 'period_end'], 'platform_payout_dismissals_period_unique');
            $table->index('dismissed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_payout_dismissals');
    }
};
