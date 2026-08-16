<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    public function run(): void
    {
        Setting::firstOrCreate(
            ['key' => 'annual_membership_fee'],
            ['value' => '500.00', 'description' => 'Annual membership fee (ZAR)'],
        );

        Setting::firstOrCreate(
            ['key' => 'non_member_surcharge'],
            ['value' => '0', 'description' => 'Extra fee for non-members per match (ZAR)'],
        );

        Setting::firstOrCreate(
            ['key' => 'lapsed_member_surcharge'],
            ['value' => '0', 'description' => 'Extra fee for lapsed members per match (ZAR)'],
        );

        // Division Rules
        Setting::firstOrCreate(
            ['key' => 'division_single_select'],
            ['value' => '1', 'description' => 'Restrict shooter to one division per match (1=yes, 0=no)'],
        );

        // Fee Structure — type/value pair lets each fee be a percentage of the
        // match fee OR a fixed rand amount per shooter.
        Setting::firstOrCreate(
            ['key' => 'saprf_fee_type'],
            ['value' => 'fixed', 'description' => 'SAPRF fee type: percentage of match fee or fixed rand amount per shooter'],
        );
        Setting::firstOrCreate(
            ['key' => 'saprf_fee_value'],
            ['value' => '50', 'description' => 'SAPRF fee value (interpreted by saprf_fee_type)'],
        );

        Setting::firstOrCreate(
            ['key' => 'platform_fee_type'],
            ['value' => 'fixed', 'description' => 'Platform fee type: percentage of match fee or fixed rand amount per shooter'],
        );
        Setting::firstOrCreate(
            ['key' => 'platform_fee_value'],
            ['value' => '0', 'description' => 'Platform fee value (interpreted by platform_fee_type)'],
        );

        // Payee for the monthly platform-fee payout. Left null on install — the
        // owner picks the user in Site Settings once, then monthly platform
        // payouts get generated from Financials → Payouts.
        Setting::firstOrCreate(
            ['key' => 'platform_operator_user_id'],
            ['value' => '', 'description' => 'User ID who receives monthly platform-fee payouts'],
        );

        // Legacy keys kept for backward compatibility; fee resolution falls back to these
        // when the new type/value keys are absent.
        Setting::firstOrCreate(
            ['key' => 'saprf_fee_percentage'],
            ['value' => '5', 'description' => '[Legacy] SAPRF federation fee as % of base match fee'],
        );

        Setting::firstOrCreate(
            ['key' => 'platform_fee_percentage'],
            ['value' => '5', 'description' => '[Legacy] Platform operator fee as % of base match fee'],
        );

        Setting::firstOrCreate(
            ['key' => 'estimated_gateway_fee_percentage'],
            ['value' => '3.5', 'description' => 'Estimated PayFast gateway fee % (for reporting only)'],
        );

        Setting::firstOrCreate(
            ['key' => 'estimated_gateway_flat_fee'],
            ['value' => '2.00', 'description' => 'Estimated PayFast flat fee per transaction in ZAR (for reporting only)'],
        );

        // Payment Gateway (PayFast)
        Setting::firstOrCreate(
            ['key' => 'payfast_merchant_id'],
            ['value' => '10000100', 'description' => 'PayFast Merchant ID'],
        );

        Setting::firstOrCreate(
            ['key' => 'payfast_merchant_key'],
            ['value' => '46f0cd694581a', 'description' => 'PayFast Merchant Key'],
        );

        Setting::firstOrCreate(
            ['key' => 'payfast_passphrase'],
            ['value' => 'jt7NOE43FZPn', 'description' => 'PayFast Passphrase'],
        );

        Setting::firstOrCreate(
            ['key' => 'payfast_sandbox'],
            ['value' => '1', 'description' => 'PayFast sandbox mode (1=sandbox, 0=live)'],
        );

        Setting::firstOrCreate(
            ['key' => 'payments_enabled'],
            ['value' => '1', 'description' => 'Enable online payments (1=yes, 0=no)'],
        );
    }
}
