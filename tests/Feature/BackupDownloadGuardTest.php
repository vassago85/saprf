<?php

use App\Models\User;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedRoles();
    Storage::fake('local');
    config(['backup.backup.name' => 'SAPRF']);

    Storage::disk('local')->put('SAPRF/backup.zip', 'archive');
    Storage::disk('local')->put('secrets.txt', 'not a backup');
});

it('lets a developer download a file inside the backup folder', function () {
    $developer = User::factory()->create();
    $developer->assignRole('developer');

    $this->actingAs($developer)
        ->get(route('developer.backups.download', ['disk' => 'local', 'path' => 'SAPRF/backup.zip']))
        ->assertOk();
});

it('refuses a developer download that leaves the backup folder', function () {
    $developer = User::factory()->create();
    $developer->assignRole('developer');

    $this->actingAs($developer)
        ->get(route('developer.backups.download', ['disk' => 'local', 'path' => 'secrets.txt']))
        ->assertNotFound();

    $this->actingAs($developer)
        ->get(route('developer.backups.download', ['disk' => 'local', 'path' => 'SAPRF/../secrets.txt']))
        ->assertNotFound();
});

it('hides backups from the owner role', function () {
    $owner = User::factory()->create();
    $owner->assignRole('owner');

    $this->actingAs($owner)
        ->get(route('developer.backups.index'))
        ->assertForbidden();
});
