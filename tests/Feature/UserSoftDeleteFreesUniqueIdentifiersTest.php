<?php

use App\Models\User;

it('nulls sa_id_number and passport_number when a user is soft-deleted', function () {
    $user = User::factory()->create([
        'sa_id_number' => '5702230089084',
        'passport_number' => 'A12345678',
    ]);

    $user->delete();

    $reloaded = User::withTrashed()->find($user->id);

    expect($reloaded)->not->toBeNull()
        ->and($reloaded->trashed())->toBeTrue()
        ->and($reloaded->sa_id_number)->toBeNull()
        ->and($reloaded->passport_number)->toBeNull();
});

it('reflects the nulled identifiers on the in-memory model after delete', function () {
    $user = User::factory()->create([
        'sa_id_number' => '5702230089084',
        'passport_number' => 'A12345678',
    ]);

    $user->delete();

    expect($user->sa_id_number)->toBeNull()
        ->and($user->passport_number)->toBeNull()
        ->and($user->isDirty(['sa_id_number', 'passport_number']))->toBeFalse();
});

it('lets another user claim the SA ID after the original owner is soft-deleted', function () {
    $original = User::factory()->create([
        'sa_id_number' => '5702230089084',
    ]);

    $original->delete();

    $newUser = User::factory()->create([
        'sa_id_number' => '5702230089084',
    ]);

    expect($newUser->sa_id_number)->toBe('5702230089084')
        ->and(User::where('sa_id_number', '5702230089084')->count())->toBe(1)
        ->and(User::withTrashed()->where('sa_id_number', '5702230089084')->count())->toBe(1);
});

it('does not run the null-out hook on force delete', function () {
    $user = User::factory()->create([
        'sa_id_number' => '5702230089084',
        'passport_number' => 'A12345678',
    ]);
    $id = $user->id;

    $user->forceDelete();

    expect(User::withTrashed()->find($id))->toBeNull();
});
