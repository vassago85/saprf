<?php

namespace App\Navigation;

use App\Http\Controllers\ApprovalController;
use App\Models\AnnouncementRecipient;
use App\Models\ContactMessage;
use App\Models\CreditRefundRequest;
use App\Models\MatchRegistration;
use App\Models\Membership;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * Single source of truth for the authenticated sidebar.
 *
 * The admin nav is organised into two super-groups (`Operations` and
 * `Governance`) each containing collapsible sections (Competition / Members
 * / Teams & selection / Communications under Operations; ExCo / Finance /
 * Reports / Settings under Governance). This mirrors the final SAPRF
 * dashboard mockup.
 *
 * Visibility is the intersection of:
 *   1. the user's Admin / Shooter view mode (navigation filtering only)
 *   2. the user's Spatie roles (authoritative — never weakened here)
 *
 * Route middleware / policies remain the access-control layer.
 */
final class SidebarMenu
{
    public const CONTEXT_ADMIN = 'admin';

    public const CONTEXT_SHOOTER = 'shooter';

    /** Super-group labels shown as a sub-divider in the admin sidebar. */
    public const SUPER_OPERATIONS = 'Operations';

    public const SUPER_GOVERNANCE = 'Governance';

    /** Collapsed-by-default admin groups (unless they own the active route). */
    private const DEFAULT_COLLAPSED_ADMIN = ['exco', 'finance', 'reports', 'settings'];

    /** Badge counts are expensive — cache per user+key for 60s. */
    private const BADGE_TTL_SECONDS = 60;

    /**
     * @return list<array{
     *     key: string,
     *     heading: ?string,
     *     super_group: ?string,
     *     contexts: list<string>,
     *     expandable: bool,
     *     items: list<array<string, mixed>>
     * }>
     */
    public static function catalog(): array
    {
        $dashboard = [
            'id' => 'dashboard',
            'label' => 'Dashboard',
            'route' => 'dashboard',
            'icon' => 'home',
            'contexts' => [self::CONTEXT_ADMIN, self::CONTEXT_SHOOTER],
            'roles' => null,
            'current_route_is' => ['dashboard'],
        ];

        $rulesAndDocuments = [
            'id' => 'documents',
            'label' => 'Rules & documents',
            'route' => 'documents.index',
            'icon' => 'document-text',
            'contexts' => [self::CONTEXT_ADMIN, self::CONTEXT_SHOOTER],
            'roles' => null,
            'current_route_is' => ['documents.*', 'selection.policy.public', 'legal.*', 'rules.*'],
        ];

        $inbox = [
            'id' => 'communications',
            'label' => 'Messages',
            'route' => 'communications.index',
            'icon' => 'inbox',
            'contexts' => [self::CONTEXT_ADMIN, self::CONTEXT_SHOOTER],
            'roles' => null,
            'current_route_is' => ['communications.*'],
            'badge' => 'communications_unread',
            'badge_color' => 'emerald',
        ];

        $iprf = [
            'id' => 'iprf',
            'label' => 'IPRF',
            'route' => 'iprf.index',
            'icon' => 'flag',
            'contexts' => [self::CONTEXT_ADMIN, self::CONTEXT_SHOOTER],
            'roles' => null,
            'current_route_is' => ['iprf.*'],
        ];

        return [
            // ─── Shared: Dashboard
            [
                'key' => 'main',
                'heading' => null,
                'super_group' => null,
                'contexts' => [self::CONTEXT_ADMIN, self::CONTEXT_SHOOTER],
                'expandable' => false,
                'items' => [$dashboard],
            ],

            // ─── Admin / Operations: Competition
            [
                'key' => 'competition_admin',
                'heading' => 'Competition',
                'super_group' => self::SUPER_OPERATIONS,
                'contexts' => [self::CONTEXT_ADMIN],
                'expandable' => true,
                'items' => [
                    [
                        'id' => 'events-admin',
                        'label' => 'Events',
                        'route' => 'events.index',
                        'icon' => 'calendar-days',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => null,
                        'current_route_is' => ['events.*'],
                        'current_is' => 'events*',
                    ],
                    [
                        'id' => 'manage-matches',
                        'label' => 'Matches',
                        'route' => 'matches.index',
                        'icon' => 'trophy',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin', 'match_director'],
                        'current_route_is' => ['matches.*'],
                    ],
                    [
                        'id' => 'registrations-admin',
                        'label' => 'Registrations',
                        'route' => 'registrations.index',
                        'icon' => 'clipboard-document-list',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin', 'match_director'],
                        'current_route_is' => ['registrations.*'],
                        'auto_switch' => false,
                    ],
                    [
                        'id' => 'score-imports',
                        'label' => 'Score imports',
                        'route' => 'score-imports.index',
                        'icon' => 'arrow-up-tray',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin', 'match_director'],
                        'current_route_is' => ['score-imports.*'],
                        'badge' => 'score_imports_pending',
                        'badge_color' => 'amber',
                    ],
                    [
                        'id' => 'scores',
                        'label' => 'Scores',
                        'route' => 'scores.index',
                        'icon' => 'document-chart-bar',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin', 'match_director'],
                        'current_route_is' => ['scores.*'],
                    ],
                    [
                        'id' => 'standings-admin',
                        'label' => 'Standings',
                        'route' => 'standings.public',
                        'icon' => 'chart-bar',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => null,
                        'current_route_is' => ['standings.*'],
                        'current_is' => 'standings*',
                    ],
                    [
                        'id' => 'venues',
                        'label' => 'Venues',
                        'route' => 'venues.index',
                        'icon' => 'map-pin',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin', 'match_director'],
                        'current_route_is' => ['venues.*'],
                    ],
                    [
                        'id' => 'divisions',
                        'label' => 'Divisions',
                        'route' => 'divisions.index',
                        'icon' => 'squares-2x2',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner'],
                        'current_route_is' => ['divisions.*'],
                    ],
                    [
                        'id' => 'qualification-rules',
                        'label' => 'Qualification rules',
                        'route' => 'qualification-rules.index',
                        'icon' => 'cog-6-tooth',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner'],
                        'current_route_is' => ['qualification-rules.*'],
                    ],
                ],
            ],

            // ─── Admin / Operations: Members
            [
                'key' => 'members',
                'heading' => 'Members',
                'super_group' => self::SUPER_OPERATIONS,
                'contexts' => [self::CONTEXT_ADMIN],
                'expandable' => true,
                'items' => [
                    [
                        'id' => 'memberships',
                        'label' => 'Memberships',
                        'route' => 'memberships.index',
                        'icon' => 'identification',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'chair', 'owner', 'admin'],
                        'current_route_is' => ['memberships.*'],
                    ],
                    [
                        'id' => 'approvals',
                        'label' => 'Approvals',
                        'route' => 'approvals.index',
                        'icon' => 'check-badge',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'chair', 'owner', 'admin'],
                        'current_route_is' => ['approvals.*'],
                        'badge' => 'pending_approvals',
                        'badge_color' => 'amber',
                    ],
                    [
                        'id' => 'clubs',
                        'label' => 'Clubs',
                        'route' => 'clubs.index',
                        'icon' => 'building-office-2',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'chair', 'owner', 'admin'],
                        'current_route_is' => ['clubs.*'],
                    ],
                    [
                        'id' => 'provincial-committees',
                        'label' => 'Provincial committees',
                        'route' => 'provincial-committees.index',
                        'icon' => 'building-library',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner'],
                        'current_route_is' => ['provincial-committees.*'],
                    ],
                    [
                        'id' => 'user-management',
                        'label' => 'Users',
                        'route' => 'user-management.index',
                        'icon' => 'user-group',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner'],
                        'current_route_is' => ['user-management.*'],
                    ],
                ],
            ],

            // ─── Admin / Operations: Teams & selection
            [
                'key' => 'selection',
                'heading' => 'Teams & selection',
                'super_group' => self::SUPER_OPERATIONS,
                'contexts' => [self::CONTEXT_ADMIN],
                'expandable' => true,
                'items' => [
                    [
                        'id' => 'selection-cycles',
                        'label' => 'Selection cycles',
                        'route' => 'selection.cycles.index',
                        'icon' => 'flag',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin', 'iprf_selector'],
                        'current_route_is' => ['selection.*'],
                    ],
                    [
                        'id' => 'team-years',
                        'label' => 'Team years',
                        'route' => 'team-years.index',
                        'icon' => 'calendar',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin'],
                        'current_route_is' => ['team-years.*'],
                    ],
                    [
                        'id' => 'appearances',
                        'label' => 'Appearances & colours',
                        'route' => 'appearances.index',
                        'icon' => 'star',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin'],
                        'current_route_is' => ['appearances.*'],
                    ],
                    array_merge($iprf, ['contexts' => [self::CONTEXT_ADMIN]]),
                ],
            ],

            // ─── Admin / Operations: Communications
            [
                'key' => 'comms',
                'heading' => 'Communications',
                'super_group' => self::SUPER_OPERATIONS,
                'contexts' => [self::CONTEXT_ADMIN],
                'expandable' => true,
                'items' => [
                    array_merge($inbox, ['contexts' => [self::CONTEXT_ADMIN]]),
                    [
                        'id' => 'announcements',
                        'label' => 'Announcements',
                        'route' => 'announcements.index',
                        'icon' => 'megaphone',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'chair'],
                        'current_route_is' => ['announcements.*'],
                    ],
                    [
                        'id' => 'saved-lists',
                        'label' => 'Saved lists',
                        'route' => 'saved-lists.index',
                        'icon' => 'user-group',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'chair'],
                        'current_route_is' => ['saved-lists.*'],
                    ],
                    [
                        'id' => 'contact-messages',
                        'label' => 'Contact enquiries',
                        'route' => 'contact-messages.index',
                        'icon' => 'envelope',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'chair', 'owner', 'admin'],
                        'current_route_is' => ['contact-messages.*'],
                        'badge' => 'unhandled_contacts',
                        'badge_color' => 'amber',
                    ],
                    [
                        'id' => 'media-albums',
                        'label' => 'Media albums',
                        'route' => 'media.albums.index',
                        'icon' => 'photo',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin'],
                        'current_route_is' => ['media.albums.*'],
                    ],
                ],
            ],

            // ─── Admin / Governance: ExCo
            [
                'key' => 'exco',
                'heading' => 'ExCo',
                'super_group' => self::SUPER_GOVERNANCE,
                'contexts' => [self::CONTEXT_ADMIN],
                'expandable' => true,
                'items' => [
                    [
                        'id' => 'exco-meetings',
                        'label' => 'Meetings',
                        'route' => 'exco.meetings.index',
                        'icon' => 'calendar-days',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'chair'],
                        'current_route_is' => ['exco.meetings.*'],
                    ],
                    [
                        'id' => 'exco-actions',
                        'label' => 'Actions',
                        'route' => 'exco.actions.index',
                        'icon' => 'clipboard-document-check',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'chair'],
                        'current_route_is' => ['exco.actions.*'],
                    ],
                    [
                        'id' => 'exco-disciplinary',
                        'label' => 'Disciplinary',
                        'route' => 'exco.disciplinary.index',
                        'icon' => 'shield-exclamation',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'chair'],
                        'current_route_is' => ['exco.disciplinary.*'],
                    ],
                    [
                        'id' => 'exco-members',
                        'label' => 'Members',
                        'route' => 'exco.members.index',
                        'icon' => 'users',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'chair'],
                        'current_route_is' => ['exco.members.*'],
                    ],
                    array_merge($rulesAndDocuments, [
                        'id' => 'exco-documents',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'chair'],
                    ]),
                    [
                        'id' => 'exco-prompts',
                        'label' => 'AI prompts',
                        'route' => 'exco.prompts',
                        'icon' => 'sparkles',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'chair'],
                        'current_route_is' => ['exco.prompts'],
                    ],
                ],
            ],

            // ─── Admin / Governance: Finance
            [
                'key' => 'finance',
                'heading' => 'Finance',
                'super_group' => self::SUPER_GOVERNANCE,
                'contexts' => [self::CONTEXT_ADMIN],
                'expandable' => true,
                'items' => [
                    [
                        'id' => 'financials-dashboard',
                        'label' => 'Overview',
                        'route' => 'financials.dashboard',
                        'icon' => 'banknotes',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin'],
                        'current_route_is' => ['financials.dashboard'],
                    ],
                    [
                        'id' => 'financials-income',
                        'label' => 'Income',
                        'route' => 'financials.income',
                        'icon' => 'arrow-trending-up',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin'],
                        'current_route_is' => ['financials.income*'],
                    ],
                    [
                        'id' => 'financials-expenses',
                        'label' => 'Expenses',
                        'route' => 'financials.expenses',
                        'icon' => 'receipt-percent',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin'],
                        'current_route_is' => ['financials.expenses*'],
                    ],
                    [
                        'id' => 'financials-transactions',
                        'label' => 'Transactions',
                        'route' => 'financials.transactions',
                        'icon' => 'queue-list',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin'],
                        'current_route_is' => ['financials.transactions'],
                    ],
                    [
                        'id' => 'financials-credit-refunds',
                        'label' => 'Refunds',
                        'route' => 'financials.credit-refunds.index',
                        'icon' => 'arrow-uturn-left',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin'],
                        'current_route_is' => ['financials.credit-refunds.*'],
                        'badge' => 'pending_credit_refunds',
                        'badge_color' => 'amber',
                    ],
                    [
                        'id' => 'financials-payouts',
                        'label' => 'Payouts',
                        'route' => 'financials.payouts',
                        'icon' => 'credit-card',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin'],
                        'current_route_is' => ['financials.payouts*'],
                        'badge' => 'pending_md_payouts',
                        'badge_color' => 'amber',
                    ],
                    [
                        'id' => 'fees',
                        'label' => 'Membership fees',
                        'route' => 'fees.index',
                        'icon' => 'currency-dollar',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner'],
                        'current_route_is' => ['fees.*'],
                    ],
                    [
                        'id' => 'sponsors',
                        'label' => 'Sponsors',
                        'route' => 'sponsors.index',
                        'icon' => 'megaphone',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'chair', 'owner', 'admin'],
                        'current_route_is' => ['sponsors.*'],
                    ],
                    [
                        'id' => 'sponsor-tiers',
                        'label' => 'Sponsor tiers',
                        'route' => 'sponsor-tiers.index',
                        'icon' => 'tag',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner'],
                        'current_route_is' => ['sponsor-tiers.*'],
                    ],
                ],
            ],

            // ─── Admin / Governance: Reports
            [
                'key' => 'reports',
                'heading' => 'Reports',
                'super_group' => self::SUPER_GOVERNANCE,
                'contexts' => [self::CONTEXT_ADMIN],
                'expandable' => true,
                'items' => [
                    [
                        'id' => 'reports-hub',
                        'label' => 'Reports hub',
                        'route' => 'reports.index',
                        'icon' => 'chart-bar',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin'],
                        'current_route_is' => ['reports.index'],
                    ],
                    [
                        'id' => 'reports-participation',
                        'label' => 'Participation',
                        'route' => 'reports.participation',
                        'icon' => 'chart-bar-square',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin'],
                        'current_route_is' => ['reports.participation'],
                    ],
                    [
                        'id' => 'reports-selection',
                        'label' => 'Selection',
                        'route' => 'reports.selection',
                        'icon' => 'flag',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin'],
                        'current_route_is' => ['reports.selection'],
                    ],
                    [
                        'id' => 'reports-sponsorship',
                        'label' => 'Sponsorship',
                        'route' => 'reports.sponsorship',
                        'icon' => 'chart-pie',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin'],
                        'current_route_is' => ['reports.sponsorship'],
                    ],
                    [
                        'id' => 'provincial-members',
                        'label' => 'Provincial members',
                        'route' => 'provincial-members.index',
                        'icon' => 'users',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin', 'provincial_admin'],
                        'current_route_is' => ['provincial-members.*'],
                    ],
                    [
                        'id' => 'sascoc-report',
                        'label' => 'SASCOC report',
                        'route' => 'sascoc-report.index',
                        'icon' => 'document-chart-bar',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['exco', 'owner'],
                        'current_route_is' => ['sascoc-report.*'],
                    ],
                ],
            ],

            // ─── Admin / Governance: Settings
            [
                'key' => 'settings',
                'heading' => 'Settings',
                'super_group' => self::SUPER_GOVERNANCE,
                'contexts' => [self::CONTEXT_ADMIN],
                'expandable' => true,
                'items' => [
                    [
                        'id' => 'site-settings',
                        'label' => 'Site settings',
                        'route' => 'site-settings.index',
                        'icon' => 'adjustments-horizontal',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner'],
                        'current_route_is' => ['site-settings.*'],
                    ],
                    [
                        'id' => 'audit-logs',
                        'label' => 'Audit logs',
                        'route' => 'audit-logs.index',
                        'icon' => 'document-magnifying-glass',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin'],
                        'current_route_is' => ['audit-logs.*'],
                    ],
                    [
                        'id' => 'email-logs',
                        'label' => 'Email log',
                        'route' => 'email-logs.index',
                        'icon' => 'envelope',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin'],
                        'current_route_is' => ['email-logs.*'],
                    ],
                ],
            ],

            // ─── Shooter: Competition
            [
                'key' => 'competition',
                'heading' => 'Competition',
                'super_group' => null,
                'contexts' => [self::CONTEXT_SHOOTER],
                'expandable' => false,
                'items' => [
                    [
                        'id' => 'events',
                        'label' => 'Events',
                        'route' => 'events.index',
                        'icon' => 'calendar-days',
                        'contexts' => [self::CONTEXT_SHOOTER],
                        'roles' => null,
                        'current_route_is' => ['events.*'],
                        'current_is' => 'events*',
                    ],
                    [
                        'id' => 'standings',
                        'label' => 'Standings',
                        'route' => 'standings.public',
                        'icon' => 'trophy',
                        'contexts' => [self::CONTEXT_SHOOTER],
                        'roles' => null,
                        'current_route_is' => ['standings.*'],
                        'current_is' => 'standings*',
                    ],
                    [
                        'id' => 'registrations',
                        'label' => 'My registrations',
                        'route' => 'registrations.index',
                        'icon' => 'clipboard-document-list',
                        'contexts' => [self::CONTEXT_SHOOTER],
                        'roles' => null,
                        'current_route_is' => ['registrations.*'],
                        'auto_switch' => false,
                        'badge' => 'unpaid_registrations',
                        'badge_color' => 'amber',
                    ],
                ],
            ],

            // ─── Shooter: Me
            [
                'key' => 'me',
                'heading' => 'Me',
                'super_group' => null,
                'contexts' => [self::CONTEXT_SHOOTER],
                'expandable' => false,
                'items' => [
                    [
                        'id' => 'my-membership',
                        'label' => 'My membership',
                        'route' => 'my-membership',
                        'icon' => 'identification',
                        'contexts' => [self::CONTEXT_SHOOTER],
                        'roles' => null,
                        'current_route_is' => ['my-membership', 'membership.certificate', 'membership.activity-report'],
                        'badge' => 'membership_attention',
                        'badge_color' => 'amber',
                    ],
                    [
                        'id' => 'family',
                        'label' => 'My family',
                        'route' => 'family.index',
                        'icon' => 'users',
                        'contexts' => [self::CONTEXT_SHOOTER],
                        'roles' => null,
                        'current_route_is' => ['family.*'],
                        'badge' => 'family_count',
                        'badge_color' => 'emerald',
                    ],
                    [
                        'id' => 'invoices',
                        'label' => 'My invoices',
                        'route' => 'invoices.index',
                        'icon' => 'document-text',
                        'contexts' => [self::CONTEXT_SHOOTER],
                        'roles' => null,
                        'current_route_is' => ['invoices.*'],
                    ],
                ],
            ],

            // ─── Shooter: Kit
            [
                'key' => 'kit',
                'heading' => 'Kit',
                'super_group' => null,
                'contexts' => [self::CONTEXT_SHOOTER],
                'expandable' => false,
                'items' => [
                    [
                        'id' => 'rifles',
                        'label' => 'My rifles',
                        'route' => 'rifle-configurations.index',
                        'icon' => 'wrench-screwdriver',
                        'contexts' => [self::CONTEXT_SHOOTER],
                        'roles' => null,
                        'current_route_is' => ['rifle-configurations.*'],
                    ],
                    [
                        'id' => 'ammo',
                        'label' => 'My ammo',
                        'route' => 'ammo-loads.index',
                        'icon' => 'fire',
                        'contexts' => [self::CONTEXT_SHOOTER],
                        'roles' => null,
                        'current_route_is' => ['ammo-loads.*'],
                    ],
                    [
                        'id' => 'barrels',
                        'label' => 'My barrels',
                        'route' => 'barrels.index',
                        'icon' => 'cube',
                        'contexts' => [self::CONTEXT_SHOOTER],
                        'roles' => null,
                        'current_route_is' => ['barrels.*'],
                    ],
                    [
                        'id' => 'load-tools',
                        'label' => 'Load tools',
                        'route' => 'load-tools.index',
                        'icon' => 'beaker',
                        'contexts' => [self::CONTEXT_SHOOTER],
                        'roles' => null,
                        'current_route_is' => ['load-tools.*', 'ladder-sessions.*', 'ammo-strings.*'],
                    ],
                ],
            ],

            // ─── Shooter: Federation
            [
                'key' => 'federation',
                'heading' => 'Federation',
                'super_group' => null,
                'contexts' => [self::CONTEXT_SHOOTER],
                'expandable' => false,
                'items' => [
                    array_merge($rulesAndDocuments, ['contexts' => [self::CONTEXT_SHOOTER]]),
                    array_merge($inbox, ['contexts' => [self::CONTEXT_SHOOTER], 'label' => 'Inbox']),
                    array_merge($iprf, ['contexts' => [self::CONTEXT_SHOOTER]]),
                ],
            ],
        ];
    }

    /**
     * Sections visible to this user in this view mode, with hrefs / current
     * / badges resolved for the current request.
     *
     * @return list<array{
     *     key: string,
     *     heading: ?string,
     *     super_group: ?string,
     *     expandable: bool,
     *     default_open: bool,
     *     expanded: bool,
     *     has_current: bool,
     *     badge: int|null,
     *     badge_color: string,
     *     items: list<array<string, mixed>>
     * }>
     */
    public static function sectionsFor(User $user, string $viewMode, ?Request $request = null): array
    {
        $request ??= request();
        $activeSectionKey = null;
        $resolved = [];

        foreach (self::catalog() as $section) {
            if (! in_array($viewMode, $section['contexts'], true)) {
                continue;
            }

            $items = [];
            $sectionHasCurrent = false;

            foreach ($section['items'] as $item) {
                if (! in_array($viewMode, $item['contexts'], true)) {
                    continue;
                }

                if (is_array($item['roles']) && ! $user->hasAnyRole($item['roles'])) {
                    continue;
                }

                $current = self::itemIsCurrent($item, $request);
                $sectionHasCurrent = $sectionHasCurrent || $current;

                $items[] = [
                    'id' => $item['id'],
                    'label' => $item['label'],
                    'icon' => $item['icon'],
                    'href' => route($item['route']),
                    'route' => $item['route'],
                    'current' => $current,
                    'badge' => self::resolveBadge($item['badge'] ?? null, $user),
                    'badge_color' => $item['badge_color'] ?? 'emerald',
                ];
            }

            if ($items === []) {
                continue;
            }

            [$sectionBadge, $sectionBadgeColor] = self::aggregateSectionBadge($items);

            $expandable = $section['expandable'] && $viewMode === self::CONTEXT_ADMIN;
            $defaultOpen = ! in_array($section['key'], self::DEFAULT_COLLAPSED_ADMIN, true);

            $resolved[] = [
                'key' => $section['key'],
                'heading' => $section['heading'],
                'super_group' => $section['super_group'] ?? null,
                'expandable' => $expandable,
                'default_open' => $defaultOpen,
                'expanded' => ! $expandable
                    || $sectionHasCurrent
                    || $defaultOpen,
                'has_current' => $sectionHasCurrent,
                'badge' => $sectionBadge,
                'badge_color' => $sectionBadgeColor,
                'items' => $items,
            ];

            if ($sectionHasCurrent) {
                $activeSectionKey = $section['key'];
            }
        }

        if ($activeSectionKey === null) {
            return $resolved;
        }

        // The group that owns the active route is always open; collapsed-by-default
        // groups return to their collapsed state unless they own the route.
        return array_map(function (array $section) use ($activeSectionKey): array {
            if (! $section['expandable']) {
                return $section;
            }

            if ($section['key'] === $activeSectionKey) {
                $section['expanded'] = true;

                return $section;
            }

            $section['expanded'] = $section['default_open'];

            return $section;
        }, $resolved);
    }

    /**
     * Exclusive nav context for a named route, or null when the route is
     * shared / unknown / dual-purpose (do not flip View As).
     */
    public static function exclusiveContextForRoute(?string $routeName): ?string
    {
        if ($routeName === null || $routeName === '') {
            return null;
        }

        $inAdmin = false;
        $inShooter = false;

        foreach (self::catalog() as $section) {
            foreach ($section['items'] as $item) {
                if (($item['auto_switch'] ?? true) === false) {
                    continue;
                }

                if (! self::routeMatchesItem($routeName, $item)) {
                    continue;
                }

                if (in_array(self::CONTEXT_ADMIN, $item['contexts'], true)) {
                    $inAdmin = true;
                }

                if (in_array(self::CONTEXT_SHOOTER, $item['contexts'], true)) {
                    $inShooter = true;
                }
            }
        }

        if ($inAdmin && ! $inShooter) {
            return self::CONTEXT_ADMIN;
        }

        if ($inShooter && ! $inAdmin) {
            return self::CONTEXT_SHOOTER;
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function labelsFor(User $user, string $viewMode): array
    {
        $labels = [];

        foreach (self::sectionsFor($user, $viewMode) as $section) {
            foreach ($section['items'] as $item) {
                $labels[] = $item['label'];
            }
        }

        return $labels;
    }

    /**
     * Flat list of visible items for the Ctrl/Cmd+K dialog. Each entry has
     * everything needed to render a row and navigate on click.
     *
     * @return list<array{id: string, label: string, section: ?string, href: string, icon: string}>
     */
    public static function searchItemsFor(User $user, string $viewMode, ?Request $request = null): array
    {
        $out = [];

        foreach (self::sectionsFor($user, $viewMode, $request) as $section) {
            foreach ($section['items'] as $item) {
                $out[] = [
                    'id' => $section['key'].':'.$item['id'],
                    'label' => $item['label'],
                    'section' => $section['heading'],
                    'href' => $item['href'],
                    'icon' => $item['icon'],
                ];
            }
        }

        return $out;
    }

    // ──────────────────────────────────────────────────────────────────────
    // Internals

    /**
     * @param  array<string, mixed>  $item
     */
    private static function itemIsCurrent(array $item, Request $request): bool
    {
        if (isset($item['current_is']) && $request->is($item['current_is'])) {
            return true;
        }

        $patterns = $item['current_route_is'] ?? [];

        return $patterns !== [] && $request->routeIs(...$patterns);
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function routeMatchesItem(string $routeName, array $item): bool
    {
        $patterns = $item['current_route_is'] ?? [];

        if ($patterns === []) {
            return ($item['route'] ?? null) === $routeName;
        }

        foreach ($patterns as $pattern) {
            if (str_ends_with($pattern, '*')) {
                $prefix = substr($pattern, 0, -1);
                if (str_starts_with($routeName, $prefix)) {
                    return true;
                }

                continue;
            }

            if ($pattern === $routeName) {
                return true;
            }
        }

        return false;
    }

    private static function resolveBadge(?string $badge, User $user): int|string|null
    {
        if ($badge === null) {
            return null;
        }

        $value = Cache::remember(
            "sidebar:badge:{$badge}:{$user->id}",
            self::BADGE_TTL_SECONDS,
            fn () => self::computeBadge($badge, $user),
        );

        return $value > 0 ? $value : null;
    }

    private static function computeBadge(string $badge, User $user): int
    {
        return match ($badge) {
            'family_count' => (int) $user->managedAccounts()->count(),
            'communications_unread' => (int) AnnouncementRecipient::query()
                ->where('user_id', $user->id)
                ->whereNull('read_at')
                ->count(),
            'pending_approvals' => (int) ApprovalController::totalPendingCount(),
            'unhandled_contacts' => (int) ContactMessage::query()->clean()->unhandled()->count(),
            'pending_md_payouts' => (int) Payout::query()
                ->where('payee_type', 'match_director')
                ->where('status', 'pending')
                ->count(),
            'pending_credit_refunds' => (int) CreditRefundRequest::query()
                ->where('status', CreditRefundRequest::STATUS_PENDING)
                ->count(),
            // Only count genuinely actionable imports (pending/queued).
            // Historical 'failed' imports stay visible on the Score imports page
            // itself but shouldn't keep lighting the sidebar months after the
            // fact — mirrors the OwnerDashboardPresenter attention logic.
            'score_imports_pending' => (int) \DB::table('score_imports')
                ->whereIn('import_status', ['pending', 'queued'])
                ->count(),
            // Only count unpaid registrations for matches that are still live —
            // past / completed / cancelled matches shouldn't keep nagging the
            // shooter after the window to actually turn up has closed.
            'unpaid_registrations' => (int) MatchRegistration::query()
                ->where('user_id', $user->id)
                ->whereIn('registration_status', ['confirmed', 'waitlisted'])
                ->whereNotIn('payment_status', ['paid', 'waived'])
                ->whereHas('match', function ($q) {
                    $q->whereDate('match_date', '>=', \Illuminate\Support\Carbon::today())
                        ->whereNotIn('status', ['cancelled', 'completed']);
                })
                ->count(),
            'membership_attention' => self::membershipAttentionCount($user),
            default => 0,
        };
    }

    private static function membershipAttentionCount(User $user): int
    {
        $membership = $user->membership;

        if (! $membership) {
            return 0;
        }

        if ($membership->shouldShowDashboardRenewalNotice()) {
            return 1;
        }

        $label = $membership->effective_status_label;
        if (in_array($label, ['Lapsed', 'Lapsed (in grace)', 'Expired'], true)) {
            return 1;
        }

        return 0;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @return array{0: int|null, 1: string}
     */
    private static function aggregateSectionBadge(array $items): array
    {
        $total = 0;
        $color = 'emerald';

        foreach ($items as $item) {
            if ($item['badge'] === null) {
                continue;
            }

            $total += (int) $item['badge'];

            if (($item['badge_color'] ?? 'emerald') === 'amber') {
                $color = 'amber';
            }
        }

        return [$total > 0 ? $total : null, $color];
    }
}
