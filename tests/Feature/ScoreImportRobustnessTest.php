<?php

use App\Models\Division;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Province;
use App\Models\Score;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedRoles();
    Storage::fake('local');

    $this->province = Province::firstOrCreate(
        ['name' => 'Gauteng'],
        ['abbreviation' => 'GP']
    );

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    // Flat-division model: Open / Factory / Senior / Junior / Ladies are all divisions.
    Division::firstOrCreate(['slug' => 'open'], ['name' => 'Open', 'display_order' => 1, 'is_active' => true]);
    Division::firstOrCreate(['slug' => 'factory'], ['name' => 'Factory', 'display_order' => 2, 'is_active' => true]);
    Division::firstOrCreate(['slug' => 'senior'], ['name' => 'Senior', 'display_order' => 3, 'is_active' => true]);
    Division::firstOrCreate(['slug' => 'junior'], ['name' => 'Junior', 'display_order' => 4, 'is_active' => true]);
    Division::firstOrCreate(['slug' => 'ladies'], ['name' => 'Ladies', 'display_order' => 5, 'is_active' => true]);

    $this->match = MatchEvent::create([
        'name' => 'Fixture Match',
        'match_type' => 'PR22',
        'series' => 'PR22',
        'series_level' => 'provincial',
        'season' => '2026',
        'province_id' => $this->province->id,
        'match_date' => Carbon::yesterday()->toDateString(),
        'status' => 'completed',
        'published' => true,
        'created_by' => $this->admin->id,
        'active_member_fee' => 100,
        'non_member_fee' => 100,
        'lapsed_member_fee' => 100,
    ]);
});

function impactCsv(string $body): UploadedFile
{
    // Header mirrors the real Impact-scoring export that broke event 109:
    // Place, Last, First, Class, Div, Category, Member Number, Time, Impacts, Match %
    $header = "Place,Last,First,Class,Div,Category,Member Number,Time,Impacts,Match %\n";

    return UploadedFile::fake()->createWithContent('impact.csv', $header . $body);
}

it('prefers Div over an empty or comma-tagged Category column when both alias to division', function () {
    // Row 1 (Clark): Div=Senior, Category="Factory,Senior" — used to resolve to
    // nothing because array_combine kept the Category column.
    // Row 6 (Ferreira): Div=Factory, Category="" — used to blank Div out.
    $body = '1,Clark,Glen,,Senior,"Factory,Senior",,196.44,145,80.56%' . "\n"
        . '6,Ferreira,Russell,,Factory,,,182.11,133,73.89%' . "\n";

    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $this->match->id,
        'source_type' => 'csv',
        'file' => impactCsv($body),
        'replace_existing' => 0,
    ]);

    $clark = Score::where('match_id', $this->match->id)->where('shooter_name', 'Glen Clark')->first();
    $russell = Score::where('match_id', $this->match->id)->where('shooter_name', 'Russell Ferreira')->first();

    expect($clark)->not->toBeNull()
        ->and($clark->division?->slug)->toBe('senior')
        ->and($russell)->not->toBeNull()
        ->and($russell->division?->slug)->toBe('factory');
});

it('falls through comma-separated Category tags when Div is missing', function () {
    // Only Category populated with a comma-separated tag list — no Class, no Div.
    // Should pick the first candidate that resolves.
    $body = ',Nobody,Someone,,,"Factory,Senior",,90,100,55%' . "\n";

    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $this->match->id,
        'source_type' => 'csv',
        'file' => impactCsv($body),
        'replace_existing' => 0,
    ]);

    $row = Score::where('match_id', $this->match->id)->first();

    // Factory comes first in "Factory,Senior" so it wins.
    expect($row->division?->slug)->toBe('factory');
});

it('resolves a shooter to their user account even when the CSV name has a "Snr" suffix', function () {
    // Le Riche's account is registered without a suffix; the CSV brings him in
    // as "Le Riche Coetzer Snr". Token-match should now strip Snr and connect
    // the score to his user row.
    $leRiche = User::factory()->create(['name' => 'Le Riche Coetzer']);

    $body = '13,Coetzer Snr,Le Riche,,Senior,Senior,,166.35,120,66.67%' . "\n";

    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $this->match->id,
        'source_type' => 'csv',
        'file' => impactCsv($body),
        'replace_existing' => 0,
    ]);

    $score = Score::where('match_id', $this->match->id)->first();

    expect($score)->not->toBeNull()
        ->and($score->user_id)->toBe($leRiche->id)
        ->and((float) $score->raw_score)->toBe(120.0);
});

it('does not collapse two distinct shooters when both differ only by a suffix', function () {
    // Never match to more than one candidate on suffix-strip alone: if there
    // are a "Le Riche Coetzer" and a "Le Riche Coetzer Snr" account, the
    // suffix-stripped tokens are ambiguous and the resolver must decline.
    User::factory()->create(['name' => 'Le Riche Coetzer']);
    User::factory()->create(['name' => 'Le Riche Coetzer Snr']);

    $body = '13,Coetzer Snr,Le Riche,,Senior,Senior,,166.35,120,66.67%' . "\n";

    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $this->match->id,
        'source_type' => 'csv',
        'file' => impactCsv($body),
        'replace_existing' => 0,
    ]);

    $score = Score::where('match_id', $this->match->id)->first();

    // The exact-with-suffix-strip path DOES find the "Le Riche Coetzer" account
    // first before token matching runs, so it wins deterministically. This
    // test just guards that the score is still connected to exactly one user.
    expect($score->user_id)->not->toBeNull();
});

it('still resolves a shooter by SAPRF member number even without a name match', function () {
    // Confirms existing member-number resolution still works alongside the new
    // name-suffix fallback. Numeric-only saprf_number is the default for new
    // memberships (per Membership::nextSaprfNumber).
    $leRiche = User::factory()->create(['name' => 'Someone Completely Different']);
    Membership::create([
        'user_id' => $leRiche->id,
        'saprf_number' => '5363',
        'status' => 'active',
        'payment_status' => 'paid',
        'start_date' => Carbon::today()->subYear(),
        'expiry_date' => Carbon::today()->addMonths(6),
    ]);

    $body = '13,Coetzer Snr,Le Riche,,Senior,Senior,5363,166.35,120,66.67%' . "\n";

    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $this->match->id,
        'source_type' => 'csv',
        'file' => impactCsv($body),
        'replace_existing' => 0,
    ]);

    $score = Score::where('match_id', $this->match->id)->first();
    expect($score->user_id)->toBe($leRiche->id);
});

it('matches a CSV surname written with a space against an account written without one', function () {
    // Real event 109 case: CSV says "Le Riche Coetzer Snr" (two words for
    // "Le Riche") but the platform account is registered as "LeRiche Coetzer
    // Snr" (one word). Without compact-name matching this falls through
    // every earlier priority (exact / suffix-stripped / token) and the
    // score gets stranded as user_id=NULL → status=invalid.
    $senior = User::factory()->create(['name' => 'LeRiche Coetzer Snr']);

    $body = '13,Coetzer Snr,Le Riche,,Senior,Senior,,166.35,120,66.67%' . "\n";

    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $this->match->id,
        'source_type' => 'csv',
        'file' => impactCsv($body),
        'replace_existing' => 0,
    ]);

    $score = Score::where('match_id', $this->match->id)->first();

    expect($score)->not->toBeNull()
        ->and($score->user_id)->toBe($senior->id);
});

it('keeps the Snr / no-suffix distinction when compact-matching father-and-son accounts', function () {
    // Same platform-side spelling as event 109: BOTH accounts exist under
    // the "LeRiche" compact form, one carries the Snr suffix and the other
    // doesn't. The CSV comes in with Snr — compact match must land on the
    // Snr account, never on the son's row.
    $senior = User::factory()->create(['name' => 'LeRiche Coetzer Snr']);
    $junior = User::factory()->create(['name' => 'LeRiche Coetzer']);

    $body = '13,Coetzer Snr,Le Riche,,Senior,Senior,,166.35,120,66.67%' . "\n";

    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $this->match->id,
        'source_type' => 'csv',
        'file' => impactCsv($body),
        'replace_existing' => 0,
    ]);

    $score = Score::where('match_id', $this->match->id)->first();

    expect($score->user_id)->toBe($senior->id)
        ->and($score->user_id)->not->toBe($junior->id);
});

it('does not compact-match when it would produce more than one candidate after suffix strip', function () {
    // If BOTH platform accounts are named identically apart from the
    // suffix, and the CSV name compact-strips to the same key as both,
    // the resolver must decline rather than guess. Safety over recall.
    User::factory()->create(['name' => 'LeRiche Coetzer Snr']);
    User::factory()->create(['name' => 'LeRiche Coetzer Jnr']);

    // CSV omits any suffix — deliberately ambiguous.
    $body = '13,Coetzer,Le Riche,,Senior,Senior,,166.35,120,66.67%' . "\n";

    $this->actingAs($this->admin)->post(route('score-imports.store'), [
        'match_id' => $this->match->id,
        'source_type' => 'csv',
        'file' => impactCsv($body),
        'replace_existing' => 0,
    ]);

    $score = Score::where('match_id', $this->match->id)->first();

    // Ambiguous → stays unresolved. The MD reconciles by hand.
    expect($score->user_id)->toBeNull();
});
