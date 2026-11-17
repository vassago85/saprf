<?php

use App\Models\AccountCredit;
use App\Models\AuditLog;
use App\Models\FinancialTransaction;
use App\Models\MatchEvent;
use App\Models\MatchRegistration;
use App\Models\Payment;
use App\Models\RifleConfiguration;
use App\Models\Score;
use App\Models\User;
use App\Notifications\FeeTransferNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    seedRoles();

    $this->holder = User::factory()->create([
        'name' => 'Natasha Britnell',
        'email' => 'natasha@britplast.co.za',
        'phone' => '0820000001',
    ]);
    $this->holder->assignRole('member');

    $this->target = User::factory()->create([
        'name' => 'Warren Britnell',
        'email' => 'warren@example.com',
        'phone' => '0820000002',
    ]);
    $this->target->assignRole('member');

    $this->admin = User::factory()->create();
    $this->admin->assignRole('admin');

    $this->match = MatchEvent::create([
        'name' => 'Rimfire PR22 GP 2-Day National',
        'match_type' => 'PR22',
        'series_level' => 'national',
        'series' => 'PR22',
        'season' => '2026',
        'match_date' => Carbon::parse('2026-11-07'),
        'status' => 'open',
        'published' => true,
        'match_director' => 'Eddie Kinnear',
        'active_member_fee' => 1400,
        'created_by' => $this->admin->id,
    ]);

    $this->rifle = RifleConfiguration::create([
        'user_id' => $this->holder->id,
        'nickname' => 'Natasha rifle',
    ]);

    $this->registration = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $this->holder->id,
        'rifle_configuration_id' => $this->rifle->id,
        'shooter_name' => 'Warren Britnell',
        'email' => $this->holder->email,
        'phone' => $this->holder->phone,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 1400,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $this->payment = Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $this->registration->id,
        'user_id' => $this->holder->id,
        'amount' => 1400,
        'status' => 'completed',
        'm_payment_id' => 'REG-MOVE-1',
        'paid_at' => now(),
    ]);

    $this->score = Score::create([
        'match_id' => $this->match->id,
        'user_id' => $this->holder->id,
        'shooter_name' => 'Warren Britnell',
        'raw_score' => 42,
        'match_date' => $this->match->match_date,
        'status' => 'valid',
    ]);
});

it('moves an entry onto another member and leaves the payment where it was', function () {
    $this->actingAs($this->admin)
        ->put(route('registrations.move-member', $this->registration), [
            'user_id' => $this->target->id,
        ])
        ->assertRedirect(route('registrations.show', $this->registration))
        ->assertSessionHas('success');

    $registration = $this->registration->fresh();

    expect($registration->user_id)->toBe($this->target->id)
        ->and($registration->shooter_name)->toBe('Warren Britnell')
        ->and($registration->email)->toBe('warren@example.com')
        ->and($registration->phone)->toBe('0820000002')
        ->and($registration->registered_by_user_id)->toBe($this->holder->id)
        ->and($registration->rifle_configuration_id)->toBeNull()
        ->and((float) $registration->fee_amount)->toBe(1400.0)
        ->and($registration->payment_status)->toBe('paid')
        ->and($this->payment->fresh()->user_id)->toBe($this->holder->id)
        ->and($this->score->fresh()->user_id)->toBe($this->target->id)
        ->and($this->score->fresh()->shooter_name)->toBe('Warren Britnell');

    $this->actingAs($this->target)
        ->get(route('registrations.show', $registration))
        ->assertOk();

    $this->actingAs($this->holder)
        ->get(route('registrations.show', $registration))
        ->assertOk();
});

it('keeps an existing entered-by account when the entry is moved', function () {
    $sponsor = User::factory()->create(['name' => 'Sponsor']);
    $this->registration->update(['registered_by_user_id' => $sponsor->id]);

    $this->actingAs($this->admin)
        ->put(route('registrations.move-member', $this->registration), [
            'user_id' => $this->target->id,
        ])
        ->assertRedirect();

    expect($this->registration->fresh()->registered_by_user_id)->toBe($sponsor->id)
        ->and($this->registration->fresh()->user_id)->toBe($this->target->id);
});

it('refuses to move an entry onto a member who is already entered', function () {
    MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $this->target->id,
        'shooter_name' => $this->target->name,
        'email' => $this->target->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 1400,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->put(route('registrations.move-member', $this->registration), [
            'user_id' => $this->target->id,
        ])
        ->assertSessionHasErrors('user_id');

    expect($this->registration->fresh()->user_id)->toBe($this->holder->id);
});

it('refuses a managed family account and an inactive member', function () {
    $this->target->forceFill(['is_managed_account' => true, 'parent_id' => $this->holder->id])->save();

    $this->actingAs($this->admin)
        ->from(route('registrations.show', $this->registration))
        ->put(route('registrations.move-member', $this->registration), [
            'user_id' => $this->target->id,
        ])
        ->assertSessionHasErrors('user_id');

    $this->target->forceFill(['is_managed_account' => false, 'parent_id' => null, 'is_active' => false])->save();

    $this->actingAs($this->admin)
        ->put(route('registrations.move-member', $this->registration), [
            'user_id' => $this->target->id,
        ])
        ->assertSessionHasErrors('user_id');

    expect($this->registration->fresh()->user_id)->toBe($this->holder->id);
});

it('refuses the member who currently holds the entry', function () {
    $this->actingAs($this->holder)
        ->put(route('registrations.move-member', $this->registration), [
            'user_id' => $this->target->id,
        ])
        ->assertForbidden();

    expect($this->registration->fresh()->user_id)->toBe($this->holder->id);
});

it('transfers a paid fee onto a member who is already entered and unpaid', function () {
    Notification::fake();

    $targetEntry = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $this->target->id,
        'shooter_name' => $this->target->name,
        'email' => $this->target->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 1400,
        'payment_status' => 'unpaid',
        'registration_status' => 'pending',
        'registered_at' => now(),
    ]);

    Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $targetEntry->id,
        'user_id' => $this->target->id,
        'amount' => 1400,
        'status' => 'pending',
        'm_payment_id' => 'REG-TARGET-PENDING',
    ]);

    $ledger = FinancialTransaction::create([
        'type' => 'payment',
        'source_type' => 'match_registration',
        'source_id' => $this->registration->id,
        'user_id' => $this->holder->id,
        'amount' => 1400,
        'description' => 'Match registration payment',
    ]);

    $this->actingAs($this->admin)
        ->put(route('registrations.transfer-fee', $this->registration), [
            'user_id' => $this->target->id,
        ])
        ->assertRedirect(route('registrations.show', $this->registration))
        ->assertSessionHas('success');

    $source = $this->registration->fresh();
    $targetEntry->refresh();

    expect($source->user_id)->toBe($this->holder->id)
        ->and($source->payment_status)->toBe('unpaid')
        ->and($source->registration_status)->toBe('confirmed')
        ->and($targetEntry->payment_status)->toBe('paid')
        ->and($targetEntry->registration_status)->toBe('confirmed')
        ->and($this->payment->fresh()->payable_id)->toBe($targetEntry->id)
        ->and($this->payment->fresh()->user_id)->toBe($this->holder->id)
        ->and($ledger->fresh()->source_id)->toBe($targetEntry->id)
        ->and(AccountCredit::query()->count())->toBe(0);

    expect(Payment::query()->where('m_payment_id', 'REG-TARGET-PENDING')->value('status'))->toBe('cancelled');

    Notification::assertSentTo($this->target, FeeTransferNotification::class, function (FeeTransferNotification $notification) {
        $mail = $notification->toMail($this->target);

        return str_contains($mail->subject, 'transferred to you')
            && str_contains(implode("\n", $mail->introLines), 'Natasha Britnell');
    });

    Notification::assertSentTo($this->holder, FeeTransferNotification::class, function (FeeTransferNotification $notification) {
        $mail = $notification->toMail($this->holder);

        return str_contains($mail->subject, 'Your entry fee was transferred')
            && str_contains(implode("\n", $mail->introLines), 'Warren Britnell')
            && str_contains(implode("\n", $mail->introLines), 'now unpaid');
    });

    expect(AuditLog::query()->where('action_type', 'registration.fee.transferred')->count())->toBe(2);
});

it('withdraws the source entry when that option is chosen and does not refund', function () {
    Notification::fake();

    $targetEntry = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $this->target->id,
        'shooter_name' => $this->target->name,
        'email' => $this->target->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 1400,
        'payment_status' => 'pending',
        'registration_status' => 'pending',
        'registered_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->put(route('registrations.transfer-fee', $this->registration), [
            'user_id' => $this->target->id,
            'withdraw_source' => '1',
        ])
        ->assertRedirect()
        ->assertSessionHas('success');

    $source = $this->registration->fresh();

    expect($source->registration_status)->toBe('cancelled')
        ->and($source->payment_status)->toBe('unpaid')
        ->and($source->cancellation_reason)->toContain('Warren Britnell')
        ->and($targetEntry->fresh()->payment_status)->toBe('paid')
        ->and(AccountCredit::query()->count())->toBe(0);

    Notification::assertSentTo($this->holder, FeeTransferNotification::class, function (FeeTransferNotification $notification) {
        $mail = $notification->toMail($this->holder);

        return str_contains(implode("\n", $mail->introLines), 'withdrawn');
    });
});

it('refuses to transfer a fee onto a member who is already paid', function () {
    MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $this->target->id,
        'shooter_name' => $this->target->name,
        'email' => $this->target->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 1400,
        'payment_status' => 'paid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->put(route('registrations.transfer-fee', $this->registration), [
            'user_id' => $this->target->id,
        ])
        ->assertSessionHasErrors('user_id');

    expect($this->registration->fresh()->payment_status)->toBe('paid')
        ->and($this->payment->fresh()->payable_id)->toBe($this->registration->id);
});

it('refuses a fee transfer from the member who holds the entry', function () {
    $this->actingAs($this->holder)
        ->put(route('registrations.transfer-fee', $this->registration), [
            'user_id' => $this->target->id,
        ])
        ->assertForbidden();
});

it('refuses a transfer when there is no completed payment, the entry is waived, or the target is not entered', function () {
    $targetEntry = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $this->target->id,
        'shooter_name' => $this->target->name,
        'email' => $this->target->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 1400,
        'payment_status' => 'unpaid',
        'registration_status' => 'pending',
        'registered_at' => now(),
    ]);

    $this->payment->update(['status' => 'pending']);

    $this->actingAs($this->admin)
        ->from(route('registrations.show', $this->registration))
        ->put(route('registrations.transfer-fee', $this->registration), [
            'user_id' => $this->target->id,
        ])
        ->assertSessionHasErrors('user_id');

    $this->payment->update(['status' => 'completed']);
    $this->registration->update(['payment_status' => 'waived']);

    $this->actingAs($this->admin)
        ->put(route('registrations.transfer-fee', $this->registration), [
            'user_id' => $this->target->id,
        ])
        ->assertSessionHasErrors('user_id');

    $this->registration->update(['payment_status' => 'paid']);
    $targetEntry->delete();

    $this->actingAs($this->admin)
        ->put(route('registrations.transfer-fee', $this->registration), [
            'user_id' => $this->target->id,
        ])
        ->assertSessionHasErrors('user_id');

    expect($this->payment->fresh()->payable_id)->toBe($this->registration->id)
        ->and($this->registration->fresh()->payment_status)->toBe('paid');
});

it('refuses an inactive member, a managed account, and the person who already holds the entry', function () {
    MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $this->target->id,
        'shooter_name' => $this->target->name,
        'email' => $this->target->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 1400,
        'payment_status' => 'unpaid',
        'registration_status' => 'pending',
        'registered_at' => now(),
    ]);

    $this->target->forceFill(['is_active' => false])->save();

    $this->actingAs($this->admin)
        ->put(route('registrations.transfer-fee', $this->registration), [
            'user_id' => $this->target->id,
        ])
        ->assertSessionHasErrors('user_id');

    $this->target->forceFill([
        'is_active' => true,
        'is_managed_account' => true,
        'parent_id' => $this->holder->id,
    ])->save();

    $this->actingAs($this->admin)
        ->put(route('registrations.transfer-fee', $this->registration), [
            'user_id' => $this->target->id,
        ])
        ->assertSessionHasErrors('user_id');

    $this->actingAs($this->admin)
        ->put(route('registrations.transfer-fee', $this->registration), [
            'user_id' => $this->holder->id,
        ])
        ->assertSessionHas('info');

    expect($this->payment->fresh()->payable_id)->toBe($this->registration->id);
});

it('moves every completed payment, cancels a stale checkout, confirms a waitlisted entry, and leaves scores where they are', function () {
    Notification::fake();

    $second = Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $this->registration->id,
        'user_id' => $this->holder->id,
        'amount' => 50,
        'status' => 'completed',
        'm_payment_id' => 'REG-MOVE-2',
        'paid_at' => now(),
    ]);

    $stale = Payment::create([
        'payable_type' => MatchRegistration::class,
        'payable_id' => $this->registration->id,
        'user_id' => $this->holder->id,
        'amount' => 1400,
        'status' => 'pending',
        'm_payment_id' => 'REG-STALE',
    ]);

    $refund = FinancialTransaction::create([
        'type' => 'refund',
        'source_type' => 'match_registration',
        'source_id' => $this->registration->id,
        'user_id' => $this->holder->id,
        'amount' => 10,
        'description' => 'Unrelated refund',
    ]);

    $targetEntry = MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $this->target->id,
        'shooter_name' => $this->target->name,
        'email' => $this->target->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 900,
        'payment_status' => 'unpaid',
        'registration_status' => 'waitlisted',
        'registered_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->put(route('registrations.transfer-fee', $this->registration), [
            'user_id' => $this->target->id,
        ])
        ->assertSessionHas('success');

    expect($this->payment->fresh()->payable_id)->toBe($targetEntry->id)
        ->and($second->fresh()->payable_id)->toBe($targetEntry->id)
        ->and($second->fresh()->user_id)->toBe($this->holder->id)
        ->and($stale->fresh()->status)->toBe('cancelled')
        ->and($stale->fresh()->payable_id)->toBe($this->registration->id)
        ->and($refund->fresh()->source_id)->toBe($this->registration->id)
        ->and($targetEntry->fresh()->payment_status)->toBe('paid')
        ->and($targetEntry->fresh()->registration_status)->toBe('confirmed')
        ->and((float) $targetEntry->fresh()->fee_amount)->toBe(900.0)
        ->and($this->score->fresh()->user_id)->toBe($this->holder->id)
        ->and($this->score->fresh()->shooter_name)->toBe('Warren Britnell');

    $audit = AuditLog::query()
        ->where('action_type', 'registration.fee.transferred')
        ->where('entity_id', $this->registration->id)
        ->first();

    expect($audit)->not->toBeNull()
        ->and($audit->reason)->toContain('Warren Britnell')
        ->and((float) $audit->new_value['amount'])->toBe(1450.0)
        ->and($audit->new_value['withdrawn'])->toBeFalse();
});

it('still transfers the fee when neither member has an email that can be delivered', function () {
    Notification::fake();

    $this->holder->update(['email' => 'natasha@import.saprf.local']);
    $this->target->update(['email' => 'warren@saprf.walkin']);

    MatchRegistration::create([
        'match_id' => $this->match->id,
        'user_id' => $this->target->id,
        'shooter_name' => $this->target->name,
        'email' => $this->target->email,
        'membership_fee_category' => 'active_member',
        'fee_amount' => 1400,
        'payment_status' => 'unpaid',
        'registration_status' => 'confirmed',
        'registered_at' => now(),
    ]);

    $this->actingAs($this->admin)
        ->put(route('registrations.transfer-fee', $this->registration), [
            'user_id' => $this->target->id,
        ])
        ->assertSessionHas('success');

    Notification::assertNothingSent();
    expect($this->payment->fresh()->payable_id)->not->toBe($this->registration->id);
});

it('shows the move control to staff and hides it from the holder', function () {
    $this->actingAs($this->admin)
        ->get(route('registrations.show', $this->registration))
        ->assertOk()
        ->assertSee('Move this entry')
        ->assertSee('Natasha Britnell');

    $this->actingAs($this->holder)
        ->get(route('registrations.show', $this->registration))
        ->assertOk()
        ->assertDontSee('Move this entry');
});
