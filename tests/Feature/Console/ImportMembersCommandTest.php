<?php

use App\Models\Club;
use App\Models\Division;
use App\Models\Membership;
use App\Models\Province;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    seedRoles();

    foreach (['match_director', 'exco', 'provincial_admin', 'developer', 'owner'] as $role) {
        Role::firstOrCreate(['name' => $role]);
    }

    Province::firstOrCreate(['name' => 'Gauteng'], ['abbreviation' => 'GP']);
    Province::firstOrCreate(['name' => 'Western Cape'], ['abbreviation' => 'WC']);

    Division::firstOrCreate(['slug' => 'open'], ['name' => 'Open', 'display_order' => 1]);
    Division::firstOrCreate(['slug' => 'ladies'], ['name' => 'Ladies', 'display_order' => 5]);
});

/**
 * Writes a CSV in the workspace's temp storage and returns its absolute path.
 * The command normally accepts project-relative paths; we pass absolute for isolation.
 */
function writeMembersCsv(string $body): string
{
    $path = storage_path('framework/testing/members_'.uniqid().'.csv');
    if (!is_dir(dirname($path))) mkdir(dirname($path), 0777, true);
    file_put_contents($path, $body);
    return $path;
}

it('prints a canonical CSV template', function () {
    Artisan::call('users:import-members', ['--template' => true]);
    $out = Artisan::output();

    expect($out)->toContain('name,email,phone,sa_id_number,date_of_birth,province,saprf_number');
    expect($out)->toContain('Jane Doe');
    expect($out)->toContain('"match_director,member"');
});

it('creates a new user + membership from a fresh row', function () {
    $csv = writeMembersCsv(<<<CSV
name,email,phone,sa_id_number,date_of_birth,province,saprf_number,membership_type,status,payment_status,start_date,expiry_date,division,role,is_active
Alice Newcomer,alice@example.com,+27 82 111 2222,8501015001080,1985-01-01,Gauteng,SAPRF-2026-00042,paid,active,paid,2026-01-01,2026-12-31,Ladies,member,1
CSV);

    Artisan::call('users:import-members', ['file' => $csv]);

    $u = User::where('email', 'alice@example.com')->first();
    expect($u)->not->toBeNull()
        ->and($u->name)->toBe('Alice Newcomer')
        ->and($u->phone)->toBe('+27 82 111 2222')
        ->and($u->sa_id_number)->toBe('8501015001080')
        ->and($u->date_of_birth?->toDateString())->toBe('1985-01-01')
        ->and($u->province->name)->toBe('Gauteng')
        ->and($u->division->slug)->toBe('ladies')
        ->and($u->hasRole('member'))->toBeTrue();

    $m = $u->membership;
    expect($m)->not->toBeNull()
        ->and($m->saprf_number)->toBe('SAPRF-2026-00042')
        ->and($m->status)->toBe('active')
        ->and($m->payment_status)->toBe('paid')
        ->and($m->expiry_date->toDateString())->toBe('2026-12-31');
});

it('merges real data onto an existing @import.saprf.local stub via normalized-name match', function () {
    $stub = User::create([
        'name' => 'Bob Stub',
        'email' => 'bob.stub@import.saprf.local',
        'password' => Hash::make('unusable'),
        'is_active' => true,
    ]);
    Membership::create([
        'user_id' => $stub->id,
        'saprf_number' => 'SAPRF-2026-99999',
        'membership_type' => 'paid',
        'status' => 'active',
        'payment_status' => 'waived',
        'start_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
    ]);

    $csv = writeMembersCsv(<<<CSV
name,email,phone,province,saprf_number,payment_status
Bob Stub,bob.real@example.com,+27 83 333 4444,Western Cape,SAPRF-2026-12345,paid
CSV);

    Artisan::call('users:import-members', ['file' => $csv]);

    $stub->refresh();
    expect($stub->email)->toBe('bob.real@example.com')
        ->and($stub->phone)->toBe('+27 83 333 4444')
        ->and($stub->province->abbreviation)->toBe('WC')
        ->and($stub->membership->saprf_number)->toBe('SAPRF-2026-12345')
        ->and($stub->membership->payment_status)->toBe('paid');
});

it('keeps two distinct real members with the same name as separate accounts', function () {
    // Two different people share a name but have different SAPRF numbers. The
    // second (blank email -> placeholder) must NOT merge onto the first.
    $csv = writeMembersCsv(<<<CSV
name,email,phone,province,saprf_number,membership_type
Hubert Wentzel,hubert@example.com,+27 82 000 0001,Gauteng,1841,full
Hubert Wentzel,,+27 82 000 0002,Western Cape,1842,free
CSV);

    Artisan::call('users:import-members', ['file' => $csv]);

    expect(User::where('name', 'Hubert Wentzel')->count())->toBe(2)
        ->and(Membership::whereIn('saprf_number', ['1841', '1842'])->count())->toBe(2);

    $first = Membership::where('saprf_number', '1841')->first()->user;
    $second = Membership::where('saprf_number', '1842')->first()->user;
    expect($first->id)->not->toBe($second->id)
        ->and($first->email)->toBe('hubert@example.com')
        ->and($second->email)->toEndWith('@import.saprf.local');
});

it('scrubs junk id numbers (GUIDs) and placeholder phones from legacy data', function () {
    $csv = writeMembersCsv(<<<CSV
name,email,phone,sa_id_number,province,saprf_number
Adriaan Barkhuizen,adri@example.com,NO PHONE,859079C3-7F35-4E69-8E18-08BD9F588644,Gauteng,1900
CSV);

    Artisan::call('users:import-members', ['file' => $csv]);

    $u = User::where('name', 'Adriaan Barkhuizen')->first();
    expect($u)->not->toBeNull()
        ->and($u->sa_id_number)->toBeNull()   // GUID dropped
        ->and($u->phone)->toBeNull();          // "NO PHONE" dropped
});

it('protects @saprf.co.za staff emails from being overwritten', function () {
    $staff = User::create([
        'name' => 'Admin Person',
        'email' => 'admin@saprf.co.za',
        'password' => Hash::make('secret'),
        'email_verified_at' => now(),
        'is_active' => true,
    ]);
    $staff->assignRole('admin');

    $csv = writeMembersCsv(<<<CSV
name,email,phone
Admin Person,new-email@example.com,+27 71 000 1111
CSV);

    Artisan::call('users:import-members', ['file' => $csv]);

    $staff->refresh();
    expect($staff->email)->toBe('admin@saprf.co.za')
        ->and($staff->phone)->toBe('+27 71 000 1111')
        ->and($staff->hasRole('admin'))->toBeTrue();
});

it('adds roles but never removes existing ones', function () {
    $u = User::create([
        'name' => 'Marc MD',
        'email' => 'marc@example.com',
        'password' => Hash::make('x'),
    ]);
    $u->assignRole('admin');
    $u->assignRole('member');

    $csv = writeMembersCsv(<<<CSV
name,email,role
Marc MD,marc@example.com,match_director
CSV);

    Artisan::call('users:import-members', ['file' => $csv]);

    $u->refresh();
    $roles = $u->getRoleNames()->sort()->values()->toArray();
    expect($roles)->toContain('admin')
        ->and($roles)->toContain('member')
        ->and($roles)->toContain('match_director');
});

it('supports --dry-run without writing anything', function () {
    $csv = writeMembersCsv(<<<CSV
name,email
Dry Runner,dry@example.com
CSV);

    Artisan::call('users:import-members', ['file' => $csv, '--dry-run' => true]);
    $out = Artisan::output();

    expect($out)->toContain('DRY RUN')
        ->and(User::where('email', 'dry@example.com')->exists())->toBeFalse();
});

it('rejects invalid rows in strict mode', function () {
    $csv = writeMembersCsv(<<<CSV
name,email,date_of_birth
Bad Data,not-an-email,not-a-date
CSV);

    Artisan::call('users:import-members', ['file' => $csv, '--strict' => true]);
    $out = Artisan::output();

    expect($out)->toContain('Import aborted')
        ->and(User::where('name', 'Bad Data')->exists())->toBeFalse();
});

it('supports --no-create to only merge, never create', function () {
    $csv = writeMembersCsv(<<<CSV
name,email
Not In DB,nope@example.com
CSV);

    Artisan::call('users:import-members', ['file' => $csv, '--no-create' => true]);

    expect(User::where('email', 'nope@example.com')->exists())->toBeFalse();
});

it('aliases common alternate CSV headers (surname, mobile, dob, member_no, roles)', function () {
    $csv = writeMembersCsv(<<<CSV
first,surname,e_mail,mobile,dob,province_code,saprf_no,roles
Sam,Aliased,sam@example.com,0821234567,15/08/1990,gp,SAPRF-2026-11111,"member,match_director"
CSV);

    Artisan::call('users:import-members', ['file' => $csv]);

    $u = User::where('email', 'sam@example.com')->first();
    expect($u)->not->toBeNull()
        ->and($u->name)->toBe('Sam Aliased')
        ->and($u->phone)->toBe('0821234567')
        ->and($u->date_of_birth?->toDateString())->toBe('1990-08-15')
        ->and($u->province->abbreviation)->toBe('GP')
        ->and($u->hasRole('member'))->toBeTrue()
        ->and($u->hasRole('match_director'))->toBeTrue()
        ->and($u->membership->saprf_number)->toBe('SAPRF-2026-11111');
});

it('creates clubs from the club column and links members to them', function () {
    $csv = writeMembersCsv(<<<CSV
name,email,province,club
Club One,one@example.com,Gauteng,Pretoria Precision Rifle Club (PPRC)
Club Two,two@example.com,Gauteng,Pretoria Precision Rifle Club (PPRC)
CSV);

    Artisan::call('users:import-members', ['file' => $csv]);

    // Both members share one newly-created club (no duplicate).
    expect(Club::count())->toBe(1);

    $club = Club::first();
    expect($club->name)->toBe('Pretoria Precision Rifle Club (PPRC)')
        ->and($club->abbreviation)->toBe('PPRC')
        ->and($club->slug)->toBe('pretoria-precision-rifle-club-pprc');

    expect(User::where('email', 'one@example.com')->first()->club_id)->toBe($club->id)
        ->and(User::where('email', 'two@example.com')->first()->club_id)->toBe($club->id);
});

it('links to an existing club case-insensitively via the primary_shooting_club alias', function () {
    $club = Club::create(['name' => 'Bloemfontein Precision Rifle Club', 'slug' => 'bfn-prc']);

    $csv = writeMembersCsv(<<<CSV
name,email,primary_shooting_club
Case Insensitive,ci@example.com,BLOEMFONTEIN PRECISION RIFLE CLUB
CSV);

    Artisan::call('users:import-members', ['file' => $csv]);

    expect(Club::count())->toBe(1)
        ->and(User::where('email', 'ci@example.com')->first()->club_id)->toBe($club->id);
});

it('treats placeholder club values as no club', function () {
    $csv = writeMembersCsv(<<<CSV
name,email,club
No Club,noclub@example.com,Still need to join a club
CSV);

    Artisan::call('users:import-members', ['file' => $csv]);

    expect(Club::count())->toBe(0)
        ->and(User::where('email', 'noclub@example.com')->first()->club_id)->toBeNull();
});
