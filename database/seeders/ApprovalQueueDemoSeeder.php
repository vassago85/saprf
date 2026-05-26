<?php

namespace Database\Seeders;

use App\Models\FirearmMake;
use App\Models\FirearmModel;
use App\Models\Province;
use App\Models\User;
use App\Models\Venue;
use Illuminate\Database\Seeder;

/**
 * Idempotent seeder that puts a handful of items into the federation approval
 * queue (pending venues + pending firearm models) so the Approvals page
 * isn't an empty queue for an EXCO walkthrough.
 *
 * Re-running is safe — every row is keyed by name with firstOrCreate, and the
 * is_approved=false flag is what drives the queue's pendingApproval() scope.
 */
class ApprovalQueueDemoSeeder extends Seeder
{
    public function run(): void
    {
        $submitter = User::where('email', 'director@saprf.co.za')->first()
            ?? User::role('match_director')->first()
            ?? User::role('member')->first();

        if (! $submitter) {
            $this->command?->warn('No user found to attribute submissions to — run RolesAndUsersSeeder first.');
            return;
        }

        $provinces = Province::all()->keyBy('abbreviation');

        // Pending venues — match directors will recognise these as "ranges
        // waiting for federation sign-off"
        $pendingVenues = [
            [
                'name' => 'Hartbeespoort Tactical Range',
                'city' => 'Hartbeespoort',
                'province' => 'NW',
                'contact_name' => 'Wikus de Klerk',
                'contact_phone' => '0824567890',
                'notes' => 'Submitted by match director — new venue for upcoming PRS regional.',
            ],
            [
                'name' => 'Stellenbosch Long Range Club',
                'city' => 'Stellenbosch',
                'province' => 'WC',
                'contact_name' => 'Annelize Brink',
                'contact_phone' => '0719876543',
                'notes' => '800m bays, suitable for centrefire matches.',
            ],
            [
                'name' => 'Bloemfontein Steel Valley',
                'city' => 'Bloemfontein',
                'province' => 'FS',
                'contact_name' => 'Theunis Maritz',
                'contact_phone' => '0832345678',
                'notes' => 'Newly built KYL bays + multi-stage layout.',
            ],
        ];

        $venuesCreated = 0;
        foreach ($pendingVenues as $data) {
            $province = $provinces[$data['province']] ?? null;
            $venue = Venue::firstOrCreate(
                ['name' => $data['name']],
                [
                    'city' => $data['city'],
                    'province_id' => $province?->id,
                    'contact_name' => $data['contact_name'],
                    'contact_phone' => $data['contact_phone'],
                    'notes' => $data['notes'],
                    'is_active' => true,
                    'is_approved' => false,
                    'submitted_by' => $submitter->id,
                ],
            );
            if ($venue->wasRecentlyCreated) {
                $venuesCreated++;
            }
        }

        // Pending firearm models — common requests SAPRF would actually see
        $pendingModels = [
            ['make' => 'Tikka',  'name' => 'T3x CTR Tac A1 (demo submission)'],
            ['make' => 'Bergara', 'name' => 'B-14R Trainer 22LR (demo submission)'],
        ];

        $modelsCreated = 0;
        foreach ($pendingModels as $data) {
            $make = FirearmMake::where('name', $data['make'])->first();
            if (! $make) {
                continue;
            }

            $model = FirearmModel::firstOrCreate(
                ['firearm_make_id' => $make->id, 'name' => $data['name']],
                [
                    'is_active' => true,
                    'is_approved' => false,
                    'user_submitted' => true,
                ],
            );

            if ($model->wasRecentlyCreated) {
                $modelsCreated++;
            }
        }

        $this->command?->info("ApprovalQueueDemoSeeder: {$venuesCreated} venues + {$modelsCreated} firearm models added to approval queue.");
    }
}
