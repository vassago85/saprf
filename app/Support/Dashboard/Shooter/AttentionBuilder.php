<?php

namespace App\Support\Dashboard\Shooter;

use App\Models\RifleConfiguration;
use App\Models\Score;
use App\Models\User;
use App\Services\QualificationService;
use Illuminate\Support\Carbon;

final class AttentionBuilder
{
    public function __construct(
        private readonly QualificationService $qualification,
    ) {}

    /**
     * @param  list<string>  $disciplines
     * @return list<array{tone: string, title: string, body: string, href?: string|null, href_label?: string|null}>
     */
    public function build(User $user, array $disciplines, ?string $season = null): array
    {
        $season = $season ?: (string) Carbon::now()->year;
        $items = [];

        foreach ($disciplines as $series) {
            $oop = $this->qualification->getQualificationStatus($user, $series, $season);
            if (($oop['required'] ?? 0) > 0 && ! ($oop['qualified'] ?? false)) {
                $completed = (int) ($oop['completed'] ?? 0);
                $required = (int) $oop['required'];
                $items[] = [
                    'tone' => 'amber',
                    'title' => "{$series} finals: out-of-province national needed",
                    'body' => "{$completed} of {$required} shot. Without it you can't be selected for {$series} finals.",
                    'href' => route('events.index'),
                    'href_label' => null,
                ];
            }
        }

        $problemScores = Score::query()
            ->with(['match.province'])
            ->where('user_id', $user->id)
            ->where(function ($q) {
                $q->where('status', 'invalid')
                    ->orWhere(function ($q2) {
                        $q2->where('status', 'valid')->where('raw_score', 0);
                    });
            })
            ->whereHas('match', function ($q) use ($season) {
                $q->where(function ($inner) use ($season) {
                    $inner->where('season', $season)->orWhereYear('match_date', $season);
                });
            })
            ->orderByDesc(
                \App\Models\MatchEvent::select('match_date')
                    ->whereColumn('matches.id', 'scores.match_id')
                    ->limit(1)
            )
            ->limit(5)
            ->get();

        foreach ($problemScores as $score) {
            $match = $score->match;
            $place = $score->displayRank();
            $date = $match?->match_date?->format('d M Y') ?? '';
            $placeBit = $place ? "placed #{$place}" : 'no place recorded';
            $reason = (float) $score->raw_score === 0.0
                ? 'shows 0 impacts'
                : 'is flagged invalid';

            $items[] = [
                'tone' => 'neutral',
                'title' => ($match?->name ?? 'A match')." {$reason}",
                'body' => trim("{$date} · {$placeBit}."),
                'href' => route('contact.create'),
                'href_label' => 'Query this score',
            ];
        }

        $membership = $user->membership;
        if ($membership?->shouldShowDashboardRenewalNotice()) {
            $days = (int) $membership->daysUntilExpiry();
            $when = $days === 0
                ? 'expires today'
                : ($days === 1 ? 'expires tomorrow' : "expires in {$days} days");
            $items[] = [
                'tone' => 'amber',
                'title' => 'Membership renewal due',
                'body' => "Your membership {$when} (".$membership->expiry_date?->format('d M Y').'). Renew to keep member rates.',
                'href' => route('my-membership'),
                'href_label' => 'Renew membership',
            ];
        } elseif ($membership && in_array($membership->effective_status_label, ['Lapsed (in grace)', 'Expired', 'Lapsed'], true)) {
            $items[] = [
                'tone' => 'amber',
                'title' => 'Membership '.$membership->effective_status_label,
                'body' => 'Renew to restore standings eligibility and member rates.',
                'href' => route('my-membership'),
                'href_label' => 'Renew membership',
            ];
        }

        $rifleCount = RifleConfiguration::forUser($user->id)->active()->count();
        if ($rifleCount === 0) {
            $labels = collect($disciplines)->map(fn ($d) => $d === 'PR22' ? 'rimfire' : 'centrefire')->implode(' and ');
            $items[] = [
                'tone' => 'neutral',
                'title' => 'No rifles set up yet',
                'body' => "Add your {$labels} rigs to track performance.",
                'href' => route('rifle-configurations.create', ['primary_series' => $disciplines[0] ?? 'PRS']),
                'href_label' => 'Add rifle',
            ];
        }

        return $items;
    }
}
