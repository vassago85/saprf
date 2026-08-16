<?php

use App\Models\MatchRegistration;
use App\Models\Membership;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('amount_gross', 10, 2)->nullable()->after('amount');
            $table->decimal('amount_fee', 10, 2)->nullable()->after('amount_gross');
            $table->decimal('amount_net', 10, 2)->nullable()->after('amount_fee');
        });

        Schema::table('membership_payments', function (Blueprint $table) {
            $table->decimal('gateway_fee', 10, 2)->nullable()->after('amount');
        });

        $this->backfillFromStoredItn();
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['amount_gross', 'amount_fee', 'amount_net']);
        });

        Schema::table('membership_payments', function (Blueprint $table) {
            $table->dropColumn('gateway_fee');
        });
    }

    private function backfillFromStoredItn(): void
    {
        $payments = DB::table('payments')
            ->where('status', 'completed')
            ->whereNotNull('gateway_response')
            ->get(['id', 'payable_type', 'payable_id', 'm_payment_id', 'gateway_response']);

        foreach ($payments as $row) {
            $itn = is_string($row->gateway_response)
                ? json_decode($row->gateway_response, true)
                : $row->gateway_response;

            if (! is_array($itn) || ! array_key_exists('amount_fee', $itn) || $itn['amount_fee'] === '' || $itn['amount_fee'] === null) {
                continue;
            }

            $fee = round(abs((float) $itn['amount_fee']), 2);
            $gross = isset($itn['amount_gross']) && $itn['amount_gross'] !== ''
                ? round((float) $itn['amount_gross'], 2)
                : null;
            $net = isset($itn['amount_net']) && $itn['amount_net'] !== ''
                ? round((float) $itn['amount_net'], 2)
                : null;

            DB::table('payments')->where('id', $row->id)->update([
                'amount_gross' => $gross,
                'amount_fee' => $fee,
                'amount_net' => $net,
            ]);

            if ($row->payable_type === MatchRegistration::class) {
                $reg = DB::table('match_registrations')->where('id', $row->payable_id)->first();
                if (! $reg) {
                    continue;
                }

                DB::table('match_registrations')->where('id', $row->payable_id)->update([
                    'gateway_fee' => $fee,
                    'md_net_amount' => round(
                        (float) $reg->fee_amount
                        - (float) $reg->saprf_fee
                        - (float) $reg->platform_fee
                        - (float) $reg->surcharge_amount
                        - $fee,
                        2
                    ),
                ]);
            }

            if ($row->payable_type === Membership::class && $row->m_payment_id) {
                DB::table('membership_payments')
                    ->where('payment_reference', $row->m_payment_id)
                    ->update(['gateway_fee' => $fee]);
            }
        }
    }
};
