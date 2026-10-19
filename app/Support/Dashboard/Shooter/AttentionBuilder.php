<?php

namespace App\Support\Dashboard\Shooter;

use App\Models\MatchRegistration;
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
     * Each item has:
     *   tone       → 'amber' | 'neutral'   (legacy — body colour)
     *   pill_tone  → 'amber' | 'red' | 'sky' (headline pill colour)
     *   pill_count → short badge text, e.g. '0/1', '2', '74d'
     *   title      → one-line headline
     *   body       → longer explanatory text
     *   href       → optional target URL
     *   href_label → optional CTA label when the body contains a trailing link
     *
     * @param  list<string>  $disciplines
     * @return list<array{tone: string, pill_tone: string, pill_count: string, title: string, body: string, href?: string|null, href_label?: string|null}>
     */
    public function build(User $user, array $disciplines, ?string $season = null): array
    {
        $season = $season ?: (string) Carbon::now()->year;
        $items = [];

        // 1. Out-of-province national missing for finals (highest priority — gates selection).
        foreach ($disciplines as $series) {
            $oop = $this->qualification->getQualificationStatus($user, $series, $season);
            if (($oop['required'] ?? 0) > 0 && ! ($oop['qualified'] ?? false)) {
                $completed = (int) ($oop['completed'] ?? 0);
                $required = (int) $oop['required'];
                $items[] = [
                    'tone' => 'amber',
                    'pill_tone' => 'amber',
                    'pill_count' => "{$completed}/{$required}",
                    'title' => "{$series} finals: out-of-province national needed",
                    'body' => "{$completed} of {$required} shot. Without it you can't be selected for {$series} finals.",
                    'href' => route('events.index'),
                    'href_label' => null,
                ];
            }
        }

        // 2. Unpaid match-entry fees (red — money overdue).
        $unpaidCount = MatchRegistration::query()
            ->where('user_id', $user->id)
            ->whereIn('registration_status', ['pending', 'confirmed'])
            ->whereNotIn('payment_status', ['paid', 'waived'])
            ->where('fee_amount', '>', 0)
            ->matchDateReached()
            ->count();

        if ($unpaidCount > 0) {
            $items[] = [
                'tone' => 'amber',
                'pill_tone' => 'red',
                'pill_count' => (string) $unpaidCount,
                'title' => $unpaidCount === 1 ? 'Unpaid match entry' : "{$unpaidCount} unpaid match entries",
                'body' => 'Payment is outstanding. Pay securely through the portal with PayFast, or reply to your match director with a POP / PayFast confirmation screenshot.',
                'href' => route('invoices.index'),
                'href_label' => 'Pay now',
            ];
        }

        // 3. Problem scores (invalid or 0-impact rows that likely need director sign-off).
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
                'pill_tone' => 'sky',
                'pill_count' => '!',
                'title' => ($match?->name ?? 'A match')." {$reason}",
                'body' => trim("{$date} · {$placeBit}."),
                'href' => route('contact.create'),
                'href_label' => 'Query this score',
            ];
        }

        // 4. Membership renewal / expired.
        $membership = $user->membership;
        if ($membership?->shouldShowDashboardRenewalNotice()) {
            $days = (int) $membership->daysUntilExpiry();
            $when = $days === 0
                ? 'expires today'
                : ($days === 1 ? 'expires tomorrow' : "expires in {$days} days");
            $items[] = [
                'tone' => 'amber',
                'pill_tone' => 'amber',
                'pill_count' => $days === 0 ? 'today' : "{$days}d",
                'title' => 'Membership renewal due',
                'body' => "Your membership {$when} (".$membership->expiry_date?->format('d M Y').'). Renew to keep member rates.',
                'href' => route('my-membership'),
                'href_label' => 'Renew membership',
            ];
        } elseif ($membership && in_array($membership->effective_status_label, ['Lapsed (in grace)', 'Expired', 'Lapsed'], true)) {
            $items[] = [
                'tone' => 'amber',
                'pill_tone' => 'red',
                'pill_count' => '!',
                'title' => 'Membership '.$membership->effective_status_label,
                'body' => 'Renew to restore standings eligibility and member rates.',
                'href' => route('my-membership'),
                'href_label' => 'Renew membership',
            ];
        }

        // 5. No rifles yet.
        $rifleCount = RifleConfiguration::forUser($user->id)->active()->count();
        if ($rifleCount === 0) {
            $labels = collect($disciplines)->map(fn ($d) => $d === 'PR22' ? 'rimfire' : 'centrefire')->implode(' and ');
            $items[] = [
                'tone' => 'neutral',
                'pill_tone' => 'sky',
                'pill_count' => '0',
                'title' => 'No rifles set up yet',
                'body' => "Add your {$labels} rigs to track performance.",
                'href' => route('rifle-configurations.create', ['primary_series' => $disciplines[0] ?? 'PRS']),
                'href_label' => 'Add rifle',
            ];
        }

        return $items;
    }
}
