<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Province;
use App\Models\User;
use App\Support\MatchName;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Seeds the provisional 2027 PR22 (rimfire) calendar from
 * SAPRF_events_2027.xlsx. Centrefire rows and the "provincial matches
 * will be published later" placeholder are not events and are skipped.
 *
 * Dates are the workbook's Excel serials converted from 1899-12-30.
 * Each row is a one-day national except PR22 SA Champs, which is the
 * two-day championship (series level "final" so it scores as the
 * championships on the 2027 log).
 *
 * Re-running is safe: a match that already exists for the same name and
 * date is left untouched, including any fee, director, or status an
 * admin has set since the first seed. New matches are published with
 * entries closed, no fee, and the director left as "To be confirmed",
 * so they show on the calendar as "Not open yet".
 */
class SeedPr22Events2027Command extends Command
{
    protected $signature = 'pr22:seed-2027-events
        {--dry-run : Print the calendar and write nothing}';

    protected $description = 'Seed the provisional 2027 PR22 rimfire nationals and SA Championships.';

    private const SEASON = '2027';

    private const NOTE = 'Provisional 2027 PR22 calendar entry. Entries are not open. The date and venue may still change.';

    /**
     * @var list<array{name: string, date: string, end: ?string, level: string, province: string, venue: ?string, city: ?string, note: ?string}>
     */
    private const EVENTS = [
        [
            'name' => 'PR22 2027 National - Balmoral',
            'date' => '2027-02-21',
            'end' => null,
            'level' => 'national',
            'province' => 'MP',
            'venue' => 'Balmoral',
            'city' => 'Balmoral',
            'note' => null,
        ],
        [
            'name' => 'PR22 2027 National - Klipskuur Warrior',
            'date' => '2027-03-14',
            'end' => null,
            'level' => 'national',
            'province' => 'GP',
            'venue' => 'Klipskuur Warrior',
            'city' => 'Klipskuur',
            'note' => null,
        ],
        [
            'name' => 'PR22 2027 National - Cape Town',
            'date' => '2027-04-11',
            'end' => null,
            'level' => 'national',
            'province' => 'WC',
            'venue' => 'Cape Town',
            'city' => 'Cape Town',
            'note' => null,
        ],
        [
            'name' => 'PR22 2027 National - Bloemfontein',
            'date' => '2027-05-16',
            'end' => null,
            'level' => 'national',
            'province' => 'FS',
            'venue' => 'Bloemfontein',
            'city' => 'Bloemfontein',
            'note' => null,
        ],
        [
            'name' => 'PR22 2027 National - Gauteng',
            'date' => '2027-06-13',
            'end' => null,
            'level' => 'national',
            'province' => 'GP',
            'venue' => null,
            'city' => 'Gauteng',
            'note' => 'Venue still to be confirmed.',
        ],
        [
            'name' => 'PR22 2027 National - Cape Town',
            'date' => '2027-07-24',
            'end' => null,
            'level' => 'national',
            'province' => 'WC',
            'venue' => 'Cape Town',
            'city' => 'Cape Town',
            'note' => null,
        ],
        [
            'name' => 'PR22 2027 National - Rassie',
            'date' => '2027-08-15',
            'end' => null,
            'level' => 'national',
            'province' => 'LP',
            'venue' => 'Rassie',
            'city' => 'Rassie',
            'note' => null,
        ],
        [
            'name' => 'PR22 2027 National - Hippo Creek',
            'date' => '2027-10-18',
            'end' => null,
            'level' => 'national',
            'province' => 'GP',
            'venue' => 'Hippo Creek',
            'city' => 'Gauteng',
            'note' => null,
        ],
        [
            'name' => 'PR22 SA Champs 2027',
            'date' => '2027-11-06',
            'end' => '2027-11-07',
            'level' => 'final',
            'province' => 'WC',
            'venue' => 'Cape Town',
            'city' => 'Cape Town',
            'note' => null,
        ],
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $provinces = Province::all()->keyBy('abbreviation');
        $missing = collect(self::EVENTS)
            ->pluck('province')
            ->unique()
            ->reject(fn (string $abbr) => $provinces->has($abbr))
            ->values();

        if ($missing->isNotEmpty()) {
            $this->error('Missing province abbreviation(s): '.$missing->implode(', ').'. Seed provinces first.');

            return self::FAILURE;
        }

        $creator = User::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->orderBy('id')->first()
            ?? User::whereHas('roles', fn ($q) => $q->where('name', 'admin'))->orderBy('id')->first()
            ?? User::whereHas('roles', fn ($q) => $q->where('name', 'developer'))->orderBy('id')->first()
            ?? User::query()->orderBy('id')->first();

        if (! $creator) {
            $this->error('No user available to own the matches. Seed a user first.');

            return self::FAILURE;
        }

        $divisionIds = Division::query()->active()->pluck('id');
        $created = 0;
        $existing = 0;
        $rows = [];

        $write = function () use ($provinces, $creator, $divisionIds, $dryRun, &$created, &$existing, &$rows): void {
            foreach (self::EVENTS as $event) {
                $name = MatchName::normalize($event['name']);
                $province = $provinces->get($event['province']);
                $match = MatchEvent::query()
                    ->where('match_type', 'PR22')
                    ->where('season', self::SEASON)
                    ->where('name', $name)
                    ->whereDate('match_date', $event['date'])
                    ->first();

                if ($match) {
                    $existing++;
                    $rows[] = [$event['date'], $name, $event['province'], $event['level'], 'already present'];

                    continue;
                }

                $rows[] = [$event['date'], $name, $event['province'], $event['level'], $dryRun ? 'would create' : 'created'];

                if ($dryRun) {
                    $created++;

                    continue;
                }

                $description = self::NOTE;
                if ($event['note']) {
                    $description .= ' '.$event['note'];
                }

                $match = MatchEvent::create([
                    'name' => $name,
                    'match_type' => 'PR22',
                    'series' => 'PR22',
                    'series_level' => $event['level'],
                    'season' => self::SEASON,
                    'province_id' => $province->id,
                    'venue_name' => $event['venue'],
                    'venue_location' => $event['city'] ? $event['city'].', '.$province->name : $province->name,
                    'city' => $event['city'],
                    'match_date' => $event['date'],
                    'match_end_date' => $event['end'],
                    'status' => 'closed',
                    'created_by' => $creator->id,
                    'match_director' => 'To be confirmed',
                    'active_member_fee' => null,
                    'non_member_fee' => 0,
                    'lapsed_member_fee' => 0,
                    'published' => true,
                    'description' => $description,
                ]);

                if ($divisionIds->isNotEmpty()) {
                    $match->divisions()->sync($divisionIds);
                }

                $created++;
            }
        };

        if ($dryRun) {
            $write();
        } else {
            DB::transaction($write);
        }

        $this->table(['Date', 'Event', 'Province', 'Level', 'Result'], $rows);
        $this->info(($dryRun ? 'Dry run. ' : '')."{$created} created, {$existing} already present.");

        return self::SUCCESS;
    }
}
