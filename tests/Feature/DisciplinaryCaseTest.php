<?php

/**
 * Disciplinary case register — creation, notes, attachments, and the
 * "empty-only" delete rule. Route-level gating lives in
 * ExcoMeetingGatingTest; here we exercise the write surface as a
 * legitimate ExCo user.
 */

use App\Enums\DisciplinaryCaseStatus;
use App\Models\DisciplinaryCase;
use App\Models\DisciplinaryCaseAttachment;
use App\Models\DisciplinaryCaseNote;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    seedRoles();
    Storage::fake('disciplinary');
});

function caseExco(): User
{
    $user = User::factory()->create(['email_verified_at' => now()]);
    $user->assignRole(['exco', 'member']);

    return $user->fresh();
}

it('opens a new case with an auto-generated reference', function () {
    $exco = caseExco();

    $response = $this->actingAs($exco)->post(route('exco.disciplinary.store'), [
        'subject_name' => 'External spectator',
        'title' => 'Safety breach at range',
        'summary' => 'Handed a rifle across the firing line.',
        'status' => DisciplinaryCaseStatus::Reported->value,
    ]);

    $response->assertRedirect();

    $case = DisciplinaryCase::firstWhere('title', 'Safety breach at range');
    expect($case)->not->toBeNull()
        ->and($case->reference)->toStartWith('DC-' . now()->year . '-')
        ->and($case->status)->toBe(DisciplinaryCaseStatus::Reported)
        ->and($case->opened_at)->not->toBeNull()
        ->and($case->created_by)->toBe($exco->id);
});

it('links a case to an on-platform member subject', function () {
    $exco = caseExco();

    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->assignRole('member');

    $this->actingAs($exco)->post(route('exco.disciplinary.store'), [
        'subject_user_id' => $member->id,
        'title' => 'Match dispute',
        'status' => DisciplinaryCaseStatus::UnderReview->value,
    ])->assertRedirect();

    $case = DisciplinaryCase::firstWhere('title', 'Match dispute');
    expect($case->subject_user_id)->toBe($member->id)
        ->and($case->subjectLabel())->toBe($member->name);
});

it('rejects a case with no subject at all', function () {
    $exco = caseExco();

    $this->actingAs($exco)->post(route('exco.disciplinary.store'), [
        'title' => 'Anonymous',
        'status' => DisciplinaryCaseStatus::Reported->value,
    ])->assertStatus(422);
});

it('adds a note to an existing case', function () {
    $exco = caseExco();

    $case = DisciplinaryCase::create([
        'reference' => 'DC-2026-100',
        'title' => 'A case',
        'subject_name' => 'Someone',
        'status' => DisciplinaryCaseStatus::Reported,
        'opened_at' => now(),
        'created_by' => $exco->id,
    ]);

    $this->actingAs($exco)->post(route('exco.disciplinary.notes.store', $case), [
        'body' => 'Spoke to the member on the phone; they will submit a written statement.',
    ])->assertRedirect();

    expect($case->notes()->count())->toBe(1)
        ->and($case->notes()->first()->created_by)->toBe($exco->id);
});

it('lets the note author remove their own note but not someone else', function () {
    $author = caseExco();

    $otherExco = User::factory()->create(['email_verified_at' => now()]);
    $otherExco->assignRole(['exco', 'member']);
    $otherExco = $otherExco->fresh();

    $case = DisciplinaryCase::create([
        'reference' => 'DC-2026-101',
        'title' => 'A case',
        'subject_name' => 'Someone',
        'status' => DisciplinaryCaseStatus::Reported,
        'opened_at' => now(),
        'created_by' => $author->id,
    ]);

    $note = DisciplinaryCaseNote::create([
        'case_id' => $case->id,
        'body' => 'Sensitive note',
        'created_by' => $author->id,
    ]);

    // A different ExCo user cannot delete author's note. Redirect
    // + error flash, not a hard 403 (matches the "error message"
    // pattern used elsewhere in the codebase for soft business rules).
    $this->actingAs($otherExco)
        ->delete(route('exco.disciplinary.notes.destroy', [$case, $note]))
        ->assertRedirect();

    expect(DisciplinaryCaseNote::find($note->id))->not->toBeNull();

    $this->actingAs($author)
        ->delete(route('exco.disciplinary.notes.destroy', [$case, $note]))
        ->assertRedirect();

    expect(DisciplinaryCaseNote::find($note->id))->toBeNull();
});

it('stores uploaded attachments on the private disciplinary disk', function () {
    $exco = caseExco();

    $case = DisciplinaryCase::create([
        'reference' => 'DC-2026-102',
        'title' => 'A case',
        'subject_name' => 'Someone',
        'status' => DisciplinaryCaseStatus::Reported,
        'opened_at' => now(),
        'created_by' => $exco->id,
    ]);

    $this->actingAs($exco)->post(route('exco.disciplinary.attachments.store', $case), [
        'file' => UploadedFile::fake()->create('evidence.pdf', 20, 'application/pdf'),
    ])->assertRedirect();

    $attachment = $case->attachments()->first();
    expect($attachment)->not->toBeNull()
        ->and($attachment->filename)->toBe('evidence.pdf');

    Storage::disk('disciplinary')->assertExists($attachment->path);
});

it('lets exco download their own case attachment', function () {
    $exco = caseExco();

    $case = DisciplinaryCase::create([
        'reference' => 'DC-2026-103',
        'title' => 'A case',
        'subject_name' => 'Someone',
        'status' => DisciplinaryCaseStatus::Reported,
        'opened_at' => now(),
        'created_by' => $exco->id,
    ]);

    Storage::disk('disciplinary')->put("{$case->id}/file.pdf", 'body');

    $attachment = DisciplinaryCaseAttachment::create([
        'case_id' => $case->id,
        'path' => "{$case->id}/file.pdf",
        'filename' => 'file.pdf',
        'mime' => 'application/pdf',
        'size' => 4,
        'uploaded_by' => $exco->id,
    ]);

    $this->actingAs($exco)
        ->get(route('exco.disciplinary.attachments.download', [$case, $attachment]))
        ->assertOk();
});

it('blocks a member from downloading a case attachment even by URL', function () {
    $exco = caseExco();

    $member = User::factory()->create(['email_verified_at' => now()]);
    $member->assignRole('member');

    $case = DisciplinaryCase::create([
        'reference' => 'DC-2026-104',
        'title' => 'A case',
        'subject_name' => 'Someone',
        'status' => DisciplinaryCaseStatus::Reported,
        'opened_at' => now(),
        'created_by' => $exco->id,
    ]);

    Storage::disk('disciplinary')->put("{$case->id}/file.pdf", 'body');

    $attachment = DisciplinaryCaseAttachment::create([
        'case_id' => $case->id,
        'path' => "{$case->id}/file.pdf",
        'filename' => 'file.pdf',
        'mime' => 'application/pdf',
        'size' => 4,
        'uploaded_by' => $exco->id,
    ]);

    $this->actingAs($member->fresh())
        ->get(route('exco.disciplinary.attachments.download', [$case, $attachment]))
        ->assertForbidden();
});

it('refuses to delete a case that has notes', function () {
    $exco = caseExco();

    $case = DisciplinaryCase::create([
        'reference' => 'DC-2026-105',
        'title' => 'A case',
        'subject_name' => 'Someone',
        'status' => DisciplinaryCaseStatus::UnderReview,
        'opened_at' => now(),
        'created_by' => $exco->id,
    ]);

    DisciplinaryCaseNote::create([
        'case_id' => $case->id,
        'body' => 'Cannot lose this trail',
        'created_by' => $exco->id,
    ]);

    $this->actingAs($exco)
        ->delete(route('exco.disciplinary.destroy', $case))
        ->assertRedirect();

    expect(DisciplinaryCase::find($case->id))->not->toBeNull();
});

it('closes a case by transitioning its status', function () {
    $exco = caseExco();

    $case = DisciplinaryCase::create([
        'reference' => 'DC-2026-106',
        'title' => 'A case',
        'subject_name' => 'Someone',
        'status' => DisciplinaryCaseStatus::Hearing,
        'opened_at' => now(),
        'created_by' => $exco->id,
    ]);

    $this->actingAs($exco)->put(route('exco.disciplinary.update', $case), [
        'subject_name' => 'Someone',
        'title' => 'A case',
        'status' => DisciplinaryCaseStatus::Closed->value,
    ])->assertRedirect();

    $case->refresh();
    expect($case->status)->toBe(DisciplinaryCaseStatus::Closed)
        ->and($case->closed_at)->not->toBeNull();
});

it('subject-search returns at least the member you asked for', function () {
    $exco = caseExco();

    $bob = User::factory()->create([
        'name' => 'Bob Sniper',
        'email_verified_at' => now(),
    ]);
    $bob->assignRole('member');

    $response = $this->actingAs($exco)
        ->get(route('exco.disciplinary.subject-search', ['q' => 'Bob']))
        ->assertOk()
        ->json();

    $ids = collect($response['results'])->pluck('id')->all();
    expect($ids)->toContain($bob->id);
});

it('subject-search refuses one-character queries', function () {
    $exco = caseExco();

    $response = $this->actingAs($exco)
        ->get(route('exco.disciplinary.subject-search', ['q' => 'B']))
        ->assertOk()
        ->json();

    expect($response['results'])->toBe([]);
});
