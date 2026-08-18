<?php

/**
 * Verifies the Gmail-2024 bulk-sender headers our
 * FederationAnnouncementNotification stamps on every outgoing email:
 *
 *   List-Unsubscribe: <mailto:...>, <https://.../email/unsubscribe/{id}?...&signature=...>
 *   List-Unsubscribe-Post: List-Unsubscribe=One-Click
 *   Precedence: bulk
 *   X-Mailgun-Variables: {"delivery_id":..., "announcement_id":..., "user_id":..., "category":"..."}
 *
 * These are what let Gmail show its built-in Unsubscribe button on
 * SAPRF broadcasts and what lets Mailgun webhook events find the
 * matching announcement_deliveries row.
 */

use App\Enums\AnnouncementCategory;
use App\Enums\AnnouncementStatus;
use App\Enums\DeliveryChannel;
use App\Enums\DeliveryStatus;
use App\Models\Announcement;
use App\Models\AnnouncementDelivery;
use App\Models\AnnouncementRecipient;
use App\Models\User;
use App\Notifications\FederationAnnouncementNotification;

beforeEach(function () {
    seedRoles();
});

function makeAnnouncementForHeaders(AnnouncementCategory $category = AnnouncementCategory::Announcement): array
{
    $exco = User::factory()->create(['email_verified_at' => now()]);
    $exco->assignRole(['exco', 'member']);

    $member = User::factory()->create(['email_verified_at' => now(), 'email' => 'member@example.test']);
    $member->assignRole('member');

    $announcement = Announcement::create([
        'title' => 'Header check',
        'body' => 'Body',
        'category' => $category,
        'priority' => 'normal',
        'status' => AnnouncementStatus::Sending,
        'created_by' => $exco->id,
    ]);

    $recipient = AnnouncementRecipient::create([
        'announcement_id' => $announcement->id,
        'user_id' => $member->id,
    ]);

    $delivery = AnnouncementDelivery::create([
        'announcement_recipient_id' => $recipient->id,
        'channel' => DeliveryChannel::Mail,
        'status' => DeliveryStatus::Queued,
    ]);

    return [$member, $announcement, $delivery];
}

it('injects List-Unsubscribe headers on the outgoing email', function () {
    [$member, $announcement, $delivery] = makeAnnouncementForHeaders();

    $mail = (new FederationAnnouncementNotification($announcement, $delivery))
        ->toMail($member);

    // Directly invoke the symfony-message closure(s) that the notification
    // registers via withSymfonyMessage — this is where our headers land.
    $email = new \Symfony\Component\Mime\Email();
    foreach ($mail->callbacks as $callback) {
        $callback($email);
    }

    $headers = $email->getHeaders();

    expect($headers->has('List-Unsubscribe'))->toBeTrue();
    expect($headers->has('List-Unsubscribe-Post'))->toBeTrue();
    expect($headers->get('List-Unsubscribe-Post')->getBodyAsString())
        ->toContain('List-Unsubscribe=One-Click');

    $listUnsub = $headers->get('List-Unsubscribe')->getBodyAsString();
    expect($listUnsub)->toContain('mailto:');
    expect($listUnsub)->toContain('/email/unsubscribe/' . $member->id);
    expect($listUnsub)->toContain('signature=');
});

it('injects X-Mailgun-Variables carrying delivery/user/announcement ids', function () {
    [$member, $announcement, $delivery] = makeAnnouncementForHeaders();

    $mail = (new FederationAnnouncementNotification($announcement, $delivery))
        ->toMail($member);

    $email = new \Symfony\Component\Mime\Email();
    foreach ($mail->callbacks as $callback) {
        $callback($email);
    }

    $variablesHeader = $email->getHeaders()->get('X-Mailgun-Variables');
    expect($variablesHeader)->not->toBeNull();

    $decoded = json_decode((string) $variablesHeader->getBodyAsString(), true);
    expect($decoded)->toBeArray()
        ->and($decoded['delivery_id'])->toBe($delivery->id)
        ->and($decoded['announcement_id'])->toBe($announcement->id)
        ->and($decoded['user_id'])->toBe($member->id)
        ->and($decoded['category'])->toBe(AnnouncementCategory::Announcement->value);
});

it('stamps Precedence: bulk on the outgoing email', function () {
    [$member, $announcement, $delivery] = makeAnnouncementForHeaders();

    $mail = (new FederationAnnouncementNotification($announcement, $delivery))
        ->toMail($member);

    $email = new \Symfony\Component\Mime\Email();
    foreach ($mail->callbacks as $callback) {
        $callback($email);
    }

    expect((string) $email->getHeaders()->get('Precedence')->getBodyAsString())->toBe('bulk');
});
