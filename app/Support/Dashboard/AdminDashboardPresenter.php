<?php

namespace App\Support\Dashboard;

use App\Http\Controllers\ApprovalController;
use App\Models\ContactMessage;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\Payout;
use App\Models\Score;
use App\Models\User;
use App\Support\Dashboard\Concerns\BuildsDashboardShell;
use Illuminate\Support\Carbon;

final class AdminDashboardPresenter
{
    use BuildsDashboardShell;

    public function build(User $user, ?string $season = null): DashboardPayload
    {
        $season = $this->season($season);

        $pendingMemberships = Membership::query()->where('status', 'pending')->count();
        $upcomingMatches = MatchEvent::query()
            ->where('match_date', '>=', Carbon::today())
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->count();
        $pendingScores = Score::query()->where('status', 'pending')->count();
        $pendingApprovals = ApprovalController::totalPendingCount();
        $pendingMdPayouts = Payout::query()
            ->where('payee_type', 'match_director')
            ->where('status', 'pending')
            ->count();
        $pendingMdTotal = (float) Payout::query()
            ->where('payee_type', 'match_director')
            ->where('status', 'pending')
            ->sum('net_amount');
        $contacts = ContactMessage::query()->clean()->unhandled()->count();

        $attention = [];
        if ($pendingMemberships > 0) {
            $attention[] = [
                'count' => $pendingMemberships,
                'label' => 'Pending memberships',
                'href' => route('memberships.index', ['status' => 'pending']),
                'tone' => 'amber',
            ];
        }
        if ($pendingApprovals > 0) {
            $attention[] = [
                'count' => $pendingApprovals,
                'label' => 'Pending approvals',
                'href' => route('approvals.index'),
                'tone' => 'amber',
            ];
        }
        if ($pendingScores > 0) {
            $attention[] = [
                'count' => $pendingScores,
                'label' => 'Pending scores',
                'href' => route('scores.index', ['status' => 'pending']),
                'tone' => 'amber',
            ];
        }
        if ($pendingMdPayouts > 0) {
            $attention[] = [
                'count' => $pendingMdPayouts,
                'label' => 'Pending MD payouts',
                'href' => route('financials.payouts'),
                'tone' => 'amber',
            ];
        }
        if ($contacts > 0) {
            $attention[] = [
                'count' => $contacts,
                'label' => $contacts === 1 ? 'Unhandled enquiry' : 'Unhandled enquiries',
                'href' => route('contact-messages.index', ['handled' => 'unhandled']),
                'tone' => 'amber',
            ];
        }

        return new DashboardPayload(
            greeting: $this->greeting($user),
            season: $season,
            showSeasonSelect: true,
            actions: [
                [
                    'label' => 'New match',
                    'href' => route('matches.create'),
                    'variant' => 'primary',
                ],
            ],
            attention: $attention,
            kpis: [
                [
                    'label' => 'Pending memberships',
                    'value' => number_format($pendingMemberships),
                    'href' => route('memberships.index', ['status' => 'pending']),
                    'hint' => 'Review and activate',
                    'accent' => $pendingMemberships > 0 ? 'amber' : null,
                ],
                [
                    'label' => 'Upcoming matches',
                    'value' => number_format($upcomingMatches),
                    'href' => route('matches.index'),
                    'hint' => 'From today forward',
                ],
                [
                    'label' => 'Pending scores',
                    'value' => number_format($pendingScores),
                    'href' => route('scores.index', ['status' => 'pending']),
                    'hint' => 'Awaiting validation',
                    'accent' => $pendingScores > 0 ? 'amber' : null,
                ],
                [
                    'label' => 'MD payouts pending',
                    'value' => money($pendingMdTotal),
                    'href' => route('financials.payouts'),
                    'hint' => number_format($pendingMdPayouts).' payout'.($pendingMdPayouts === 1 ? '' : 's'),
                ],
            ],
            primary: [
                'title' => 'Upcoming events',
                'href' => route('matches.index'),
                'href_label' => 'All matches',
                'rows' => $this->matchPanelRows($this->upcomingMatches(5)),
            ],
            secondary: [
                'title' => 'Recent activity',
                'href' => route('audit-logs.index'),
                'href_label' => 'Audit log',
                'rows' => $this->auditActivityRows(5),
            ],
        );
    }
}
