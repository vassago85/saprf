<?php

use App\Models\Club;
use App\Models\Membership;
use App\Models\Province;
use App\Models\User;
use Illuminate\Database\Migrations\Migration;

/**
 * Restore two members whose new-site records drifted from the live
 * precisionrifle.co.za admin (wrong type/expiry, missing profile fields).
 *
 * Stefan Kruger's old-site ID was 9209230000000 (date does not match the
 * stated DOB and the rest is zeros) — treated as redacted and not copied.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach ($this->members() as $row) {
            $this->correctMember($row);
        }
    }

    public function down(): void
    {
        // One-shot data correction; leaving the restored values in place is safe.
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function members(): array
    {
        return [
            [
                'name' => 'Stefan Kruger',
                'email' => 'stefan.louis.kruger@gmail.com',
                'saprf_number' => '1629',
                'phone' => '0847274887',
                'date_of_birth' => '1992-09-22',
                'sa_id_number' => null,
                'club' => 'Highveld Precision Rifle Club (HPRC)',
                'province' => 'Gauteng',
                'address_line_1' => 'Unit 50 Donegia Complex',
                'address_line_2' => '029 Donegal Road',
                'address_line_3' => 'Rangeview',
                'city' => 'Krugersdorp',
                'postal_code' => '1739',
                'expiry_date' => '2027-01-01',
            ],
            [
                'name' => 'Henna du Plessis',
                'email' => 'hennadup@gmail.com',
                'saprf_number' => '1206',
                'phone' => '27662119959',
                'date_of_birth' => '1989-06-11',
                'sa_id_number' => '8906115075087',
                'club' => 'Lowveld Precision Rifle Club',
                'province' => 'Mpumalanga',
                'address_line_1' => 'Elmswood',
                'address_line_2' => 'R37',
                'address_line_3' => null,
                'city' => 'Nelspruit',
                'postal_code' => '1200',
                'expiry_date' => '2027-07-17',
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function correctMember(array $row): void
    {
        $user = User::query()->where('email', $row['email'])->first();

        if (! $user) {
            $user = Membership::query()
                ->where('saprf_number', $row['saprf_number'])
                ->first()
                ?->user;
        }

        if (! $user) {
            return;
        }

        $province = Province::query()->where('name', $row['province'])->first();
        $club = Club::findOrCreateByName($row['club']);

        if ($club && $province && ! $club->province_id) {
            $club->update([
                'province_id' => $province->id,
                'saprf_recognised' => true,
            ]);
        }

        $saId = $row['sa_id_number'];
        if ($saId && User::query()->where('sa_id_number', $saId)->where('id', '!=', $user->id)->exists()) {
            $saId = $user->sa_id_number;
        }

        $user->fill([
            'name' => $row['name'],
            'email' => $row['email'],
            'phone' => $row['phone'],
            'date_of_birth' => $row['date_of_birth'],
            'sa_id_number' => $saId,
            'gender' => 'male',
            'ethnicity' => 'white',
            'previously_disadvantaged' => false,
            'sa_citizen' => true,
            'country_of_residence' => 'ZA',
            'province_id' => $province?->id ?? $user->province_id,
            'club_id' => $club?->id ?? $user->club_id,
            'address_line_1' => $row['address_line_1'],
            'address_line_2' => $row['address_line_2'],
            'address_line_3' => $row['address_line_3'],
            'city' => $row['city'],
            'postal_code' => $row['postal_code'],
        ])->save();

        $membership = $user->membership;
        if (! $membership) {
            return;
        }

        $number = trim((string) $membership->saprf_number);
        $attrs = [
            'membership_type' => 'full',
            'status' => 'active',
            'payment_status' => 'paid',
            'expiry_date' => $row['expiry_date'],
        ];

        if ($number === '' || str_starts_with($number, 'SAPRF-IMPORT-')) {
            $attrs['saprf_number'] = $row['saprf_number'];
        }

        $membership->update($attrs);
    }
};
