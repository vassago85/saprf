<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 29 October 2026 national series rules.
 *
 * Hunter and Mil/LEO are divisions shooters can enter. Limited, Factory
 * and Junior descriptions are updated to the signed table. The August 2023
 * divisions document is left as published; it carries a note that these
 * rules prevail for the 2027 series.
 *
 * Existing rows are matched on slug so a re-seed and this migration agree.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();

        // Descriptions only. These divisions already exist from the original
        // seed; creating them here would collide with tests that insert the
        // same slugs themselves.
        foreach ([
            'limited' => '.308 Win / 7.62x51 only; projectile maximum 185 gr; velocity maximum 3,200 fps (2027 series)',
            'factory' => 'Factory-manufactured rifle under the PRS Production rules and the SAPRF Factory Division rules (2027 series)',
            'junior' => 'Centrefire PRS: 17 or younger on 1 January. IPRF team selection: 14 to 20 at the start of the year. PR22: 12 to 18. Age checked against a government ID',
        ] as $slug => $description) {
            DB::table('divisions')->where('slug', $slug)->update([
                'description' => $description,
                'updated_at' => $now,
            ]);
        }

        foreach ([
            [
                'slug' => 'hunter',
                'name' => 'Hunter',
                'description' => 'South African division. Factory stock, no chassis, 2.5 lb minimum trigger. Centrefire rifle max US$3,000; PR22 rifle max US$2,500; optic max US$2,500',
                'display_order' => 8,
            ],
            [
                'slug' => 'mil-leo',
                'name' => 'Mil/LEO',
                'description' => 'IPRF: active full-time or part-time military or law enforcement. PRS Centrefire: full-time active duty or full-time LEO only',
                'display_order' => 9,
            ],
        ] as $row) {
            if (DB::table('divisions')->where('slug', $row['slug'])->exists()) {
                continue;
            }

            DB::table('divisions')->insert($row + [
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        DB::table('divisions')->whereIn('slug', ['hunter', 'mil-leo'])->delete();
    }
};
