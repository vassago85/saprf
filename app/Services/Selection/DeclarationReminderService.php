<?php

namespace App\Services\Selection;

use App\Models\SelectionAthlete;
use App\Models\SelectionCycle;
use App\Notifications\SelectionDeclarationReminderNotification;

/**
 * Emails shooters who have met every ELG/PART criterion except the
 * Eligibility to Compete declaration, with a link to that form.
 */
class DeclarationReminderService
{
    public function __construct(private readonly SelectionCriteriaStatus $criteria) {}

    /**
     * @return array{sent: int, undeliverable: int}
     */
    public function sendForCycle(SelectionCycle $cycle): array
    {
        $declarationCode = $this->declarationRuleId($cycle);

        $athletes = SelectionAthlete::forCycle($cycle->id)
            ->with([
                'user.membership',
                'user.club',
                'declaration',
                'participationSnapshot',
                'cycle.activePolicy',
            ])
            ->get();

        $sent = 0;
        $undeliverable = 0;
        foreach ($athletes as $athlete) {
            $user = $athlete->user;
            if ($user === null || blank($user->email)) {
                continue;
            }

            $status = $this->criteria->for($athlete);
            if (! $this->onlyDeclarationOutstanding($status, $declarationCode)) {
                continue;
            }

            if ($user->email_bounced_at !== null || $user->email_complained_at !== null) {
                $undeliverable++;

                continue;
            }

            $user->notify(new SelectionDeclarationReminderNotification($athlete));
            $sent++;
        }

        return ['sent' => $sent, 'undeliverable' => $undeliverable];
    }

    /**
     * True when the declaration rule is present and not met, and every other
     * criterion on the card is met. Review and not-met both count as outstanding.
     *
     * @param  array{eligibility?: list<array<string, mixed>>, participation?: list<array<string, mixed>>}  $criteria
     */
    public function onlyDeclarationOutstanding(array $criteria, string $declarationCode): bool
    {
        $rows = array_merge($criteria['eligibility'] ?? [], $criteria['participation'] ?? []);
        $declaration = null;

        foreach ($rows as $row) {
            if (($row['code'] ?? null) === $declarationCode) {
                $declaration = $row;

                continue;
            }

            if (($row['status'] ?? null) !== SelectionCriteriaStatus::STATUS_MET) {
                return false;
            }
        }

        if ($declaration === null) {
            return false;
        }

        return ($declaration['status'] ?? null) !== SelectionCriteriaStatus::STATUS_MET;
    }

    public function declarationRuleId(SelectionCycle $cycle): string
    {
        $rules = $cycle->activePolicy?->spec_json['eligibility']['rules'] ?? [];
        foreach ($rules as $rule) {
            if (($rule['check'] ?? null) === 'declaration_form_received' && filled($rule['id'] ?? null)) {
                return (string) $rule['id'];
            }
        }

        return $cycle->series === 'PR22' ? 'ELG-05' : 'ELG-06';
    }
}
