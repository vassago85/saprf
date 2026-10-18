<?php

namespace App\Support\Dashboard;

use App\Enums\DisciplinaryCaseStatus;
use App\Enums\ExcoActionStatus;
use App\Http\Controllers\ApprovalController;
use App\Models\ContactMessage;
use App\Models\CreditRefundRequest;
use App\Models\DisciplinaryCase;
use App\Models\ExcoAction;
use App\Models\MatchEvent;
use App\Models\Membership;
use App\Models\MembershipFeeTier;
use App\Models\QualificationRule;
use App\Models\Score;
use App\Models\ScoreImport;
use App\Models\User;
use App\Services\FinancialService;
use App\Services\SettingsService;
use App\Support\Dashboard\Concerns\BuildsDashboardShell;
use Illuminate\Support\Carbon;

final class OwnerDashboardPresenter
{
    use BuildsDashboardShell;

    public function __construct(
        private readonly FinancialService $financials,
        private readonly SettingsService $settings,
    ) {}

    public function build(User $user, ?string $season = null): DashboardPayload
    {
        $season = $this->season($season);
        $from = Carbon::createFromDate((int) $season, 1, 1)->startOfDay();
        $to = Carbon::createFromDate((int) $season, 12, 31)->endOfDay();

        $totalMembers = User::query()->count();
        $activeMemberships = Membership::query()->active()->count();
        $activePct = $totalMembers > 0 ? round(($activeMemberships / $totalMembers) * 100) : 0;

        $lapsing = Membership::query()
            ->active()
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [Carbon::today(), Carbon::today()->addDays(30)])
            ->count();

        $summary = $this->financials->platformSummary($from, $to);
        $membershipGross = (float) ($summary['membership_revenue']['gross'] ?? 0);
        $matchGross = (float) ($summary['match_revenue']['gross'] ?? 0);
        $seasonIncome = (float) ($summary['gross_income'] ?? 0);

        $seasonMatchQuery = MatchEvent::query()->where(function ($q) use ($season) {
            $q->where('season', $season)->orWhereYear('match_date', $season);
        });
        $totalMatches = (clone $seasonMatchQuery)->count();
        $completedMatches = (clone $seasonMatchQuery)->where('status', 'completed')->count();
        $upcomingMatches = (clone $seasonMatchQuery)
            ->where('match_date', '>=', Carbon::today())
            ->whereNotIn('status', ['cancelled', 'completed'])
            ->count();
        $scoresCount = Score::query()
            ->whereHas('match', fn ($q) => $q->where(function ($q2) use ($season) {
                $q2->where('season', $season)->orWhereYear('match_date', $season);
            }))
            ->count();

        $settings = $this->settings->all();
        $feeTiers = MembershipFeeTier::active()->ordered()->get();
        $footerItems = [];
        foreach ($feeTiers as $tier) {
            $footerItems[] = [
                'label' => $tier->name.' membership',
                'value' => money((float) $tier->price),
                'badge' => $tier->is_default ? 'Default' : null,
            ];
        }
        $footerItems[] = [
            'label' => 'Non-member surcharge',
            'value' => money((float) ($settings['non_member_surcharge'] ?? 0)),
            'badge' => null,
        ];
        $footerItems[] = [
            'label' => 'Lapsed surcharge',
            'value' => money((float) ($settings['lapsed_member_surcharge'] ?? 0)),
            'badge' => null,
        ];
        $rulesCount = QualificationRule::query()->count();
        $footerItems[] = [
            'label' => 'Qualification rules',
            'value' => $rulesCount.' configured →',
            'badge' => null,
            'href' => route('qualification-rules.index'),
        ];

        $actions = [];
        if ($user->hasAnyRole(['developer', 'exco', 'chair'])) {
            $actions[] = [
                'label' => 'New announcement',
                'href' => route('announcements.create'),
                'variant' => 'secondary',
            ];
        }
        if ($user->hasAnyRole(['developer', 'exco', 'owner', 'admin', 'match_director'])) {
            $actions[] = [
                'label' => 'New match',
                'href' => route('matches.create'),
                'variant' => 'primary',
            ];
        }

        return new DashboardPayload(
            greeting: $this->greeting($user),
            season: $season,
            showSeasonSelect: true,
            actions: $actions,
            attention: $this->attention($user),
            kpis: [
                [
                    'label' => 'Active members',
                    'value' => number_format($activeMemberships),
                    'href' => route('memberships.index', ['status' => 'active']),
                    'hint' => "of ".number_format($totalMembers)." · {$activePct}% active",
                    'progress' => $activePct / 100,
                ],
                [
                    'label' => 'Lapsing in 30 days',
                    'value' => number_format($lapsing),
                    'href' => route('memberships.index'),
                    'hint' => 'Send renewal reminder from the list',
                    'accent' => $lapsing > 0 ? 'amber' : null,
                ],
                [
                    'label' => 'Season income',
                    'value' => money($seasonIncome, false),
                    'href' => route('financials.dashboard', ['period' => 'season', 'season_year' => $season]),
                    'hint' => 'Memberships '.money($membershipGross, false).' · Matches '.money($matchGross, false),
                ],
                [
                    'label' => 'Matches',
                    'value' => number_format($totalMatches),
                    'href' => route('matches.index'),
                    'hint' => "{$completedMatches} completed · {$upcomingMatches} upcoming · ".number_format($scoresCount).' scores',
                ],
            ],
            primary: [
                'title' => 'Upcoming events',
                'href' => route('events.index'),
                'href_label' => 'All events',
                'rows' => $this->matchPanelRows($this->upcomingMatches(5)),
            ],
            secondary: [
                'title' => 'Recent activity',
                'href' => route('audit-logs.index'),
                'href_label' => 'Audit log',
                'rows' => $this->auditActivityRows(5),
            ],
            footer: [
                'title' => 'Pricing & rules',
                'href' => route('fees.index'),
                'href_label' => 'Edit pricing',
                'items' => $footerItems,
            ],
        );
    }

    /**
     * @return list<array{count: int, label: string, href: string, tone?: string}>
     */
    private function attention(User $user): array
    {
        $pills = [];

        $approvals = ApprovalController::totalPendingCount();
        if ($approvals > 0) {
            $pills[] = [
                'count' => $approvals,
                'label' => 'Pending approvals',
                'href' => route('approvals.index'),
                'tone' => 'amber',
            ];
        }

        $contacts = ContactMessage::query()->clean()->unhandled()->count();
        if ($contacts > 0) {
            $pills[] = [
                'count' => $contacts,
                'label' => $contacts === 1 ? 'Unread enquiry' : 'Unread enquiries',
                'href' => route('contact-messages.index', ['handled' => 'unhandled']),
                'tone' => 'amber',
            ];
        }

        $imports = ScoreImport::query()
            ->where(function ($q) {
                $q->whereIn('import_status', ['pending', 'review', 'needs_review'])
                    ->orWhere('import_status', 'like', '%pending%');
            })
            ->count();
        if ($imports > 0) {
            $pills[] = [
                'count' => $imports,
                'label' => 'Score imports to review',
                'href' => route('score-imports.index'),
                'tone' => 'amber',
            ];
        }

        $refunds = CreditRefundRequest::query()
            ->where('status', CreditRefundRequest::STATUS_PENDING)
            ->count();
        if ($refunds > 0) {
            $pills[] = [
                'count' => $refunds,
                'label' => $refunds === 1 ? 'Refund request' : 'Refund requests',
                'href' => route('financials.credit-refunds.index'),
                'tone' => 'red',
            ];
        }

        if ($user->hasAnyRole(['developer', 'exco', 'chair', 'owner'])) {
            $openCases = DisciplinaryCase::query()
                ->where('status', '!=', DisciplinaryCaseStatus::Closed)
                ->count();
            if ($openCases > 0) {
                $pills[] = [
                    'count' => $openCases,
                    'label' => $openCases === 1 ? 'Open disciplinary matter' : 'Open disciplinary matters',
                    'href' => route('exco.disciplinary.index'),
                    'tone' => 'neutral',
                ];
            }

            $overdueActions = ExcoAction::query()
                ->where('status', ExcoActionStatus::Open)
                ->whereNotNull('due_on')
                ->whereDate('due_on', '<', Carbon::today())
                ->count();
            if ($overdueActions > 0) {
                $pills[] = [
                    'count' => $overdueActions,
                    'label' => $overdueActions === 1 ? 'Overdue ExCo action' : 'Overdue ExCo actions',
                    'href' => route('exco.actions.index'),
                    'tone' => 'red',
                ];
            }
        }

        return $pills;
    }
}
