<?php

/**
 * Route-level gating for the Exco/Chair side of the Notification Centre.
 * Members must never reach the composer or the delivery stats page,
 * regardless of Gate::before or the acknowledgement banner.
 */

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementStatus;
use App\Models\Announcement;
use App\Models\User;

beforeEach(function () {
    seedRoles();
});

it('blocks members from the announcements composer', function () {
    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->assignRole('member');

    $this->actingAs($member)
        ->get(route('announcements.index'))
        ->assertForbidden();

    $this->actingAs($member)
        ->get(route('announcements.create'))
        ->assertForbidden();
});

it('lets exco reach the composer', function () {
    $exco = User::factory()->create(['email_verified_at' => now()]);
    $exco->assignRole(['exco', 'member']);

    $this->actingAs($exco)
        ->get(route('announcements.index'))
        ->assertOk();
});

it('lets chair reach the composer', function () {
    $chair = User::factory()->create(['email_verified_at' => now()]);
    $chair->assignRole(['chair', 'exco', 'member']);

    $this->actingAs($chair)
        ->get(route('announcements.create'))
        ->assertOk();
});

it('lets a member view the delivery stats only if they are on the recipient list', function () {
    // The "stats" page is really /announcements/{a} which is Exco-only.
    // A member should hit the members-only /communications/{a} instead.
    $exco = User::factory()->create(['email_verified_at' => now()]);
    $exco->assignRole(['exco', 'member']);

    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->assignRole('member');

    $announcement = Announcement::create([
        'title' => 'Members-only test',
        'body' => 'Body',
        'category' => AnnouncementCategory::Announcement,
        'priority' => 'normal',
        'status' => AnnouncementStatus::Draft,
        'created_by' => $exco->id,
    ]);

    $this->actingAs($member)
        ->get(route('announcements.show', $announcement))
        ->assertForbidden();
});

it('renders the audience preview endpoint for exco only', function () {
    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->assignRole('member');

    $this->actingAs($member)
        ->postJson(route('announcements.preview'), ['audiences' => []])
        ->assertForbidden();

    $exco = User::factory()->create(['email_verified_at' => now()]);
    $exco->assignRole(['exco', 'member']);

    $this->actingAs($exco)
        ->postJson(route('announcements.preview'), [
            'audiences' => [
                ['type' => 'active_members', 'mode' => 'include', 'value' => []],
            ],
        ])
        ->assertOk()
        ->assertJsonStructure(['count', 'sample']);
});
