<?php

namespace App\Support;

use App\Http\Controllers\ApprovalController;
use App\Models\AnnouncementRecipient;
use App\Models\ContactMessage;
use App\Models\Payout;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Single source of truth for the authenticated app sidebar.
 *
 * Visibility is the intersection of:
 *   1. the user's Admin / Shooter view mode (navigation filtering only)
 *   2. the user's Spatie roles (authoritative — never weakened here)
 *
 * Route middleware / policies remain the access-control layer.
 */
final class SidebarNavigation
{
    public const CONTEXT_ADMIN = 'admin';

    public const CONTEXT_SHOOTER = 'shooter';

    /**
     * @return list<array{
     *     key: string,
     *     heading: string,
     *     contexts: list<string>,
     *     expandable: bool,
     *     items: list<array<string, mixed>>
     * }>
     */
    public static function catalog(): array
    {
        $rulesAndDocuments = self::rulesAndDocumentsItem();
        $communications = self::communicationsItem();
        $iprf = self::iprfItem();

        return [
            [
                'key' => 'main',
                'heading' => 'Main',
                'contexts' => [self::CONTEXT_ADMIN, self::CONTEXT_SHOOTER],
                'expandable' => false,
                'items' => [
                    [
                        'id' => 'dashboard',
                        'label' => 'Dashboard',
                        'route' => 'dashboard',
                        'icon' => 'home',
                        'contexts' => [self::CONTEXT_ADMIN, self::CONTEXT_SHOOTER],
                        'roles' => null,
                        'current_route_is' => ['dashboard'],
                    ],
                ],
            ],
            [
                'key' => 'competition',
                'heading' => 'Competition',
                'contexts' => [self::CONTEXT_ADMIN, self::CONTEXT_SHOOTER],
                'expandable' => false,
                'items' => [
                    [
                        'id' => 'events',
                        'label' => 'Events',
                        'route' => 'events.index',
                        'icon' => 'calendar-days',
                        'contexts' => [self::CONTEXT_ADMIN, self::CONTEXT_SHOOTER],
                        'roles' => null,
                        'current_route_is' => ['events.*'],
                        'current_is' => 'events*',
                    ],
                    [
                        'id' => 'standings',
                        'label' => 'Standings',
                        'route' => 'standings.public',
                        'icon' => 'trophy',
                        'contexts' => [self::CONTEXT_ADMIN, self::CONTEXT_SHOOTER],
                        'roles' => null,
                        'current_route_is' => ['standings.*'],
                        'current_is' => 'standings*',
                    ],
                    [
                        'id' => 'registrations',
                        'label' => 'My Registrations',
                        'route' => 'registrations.index',
                        'icon' => 'clipboard-document-list',
                        'contexts' => [self::CONTEXT_SHOOTER],
                        'roles' => null,
                        'current_route_is' => ['registrations.*'],
                        // Privileged users in admin mode get an all-entries
                        // list on this same route — do not auto-switch away.
                        'auto_switch' => false,
                    ],
                ],
            ],
            [
                'key' => 'my_shooting',
                'heading' => 'My Shooting',
                'contexts' => [self::CONTEXT_SHOOTER],
                'expandable' => false,
                'items' => [
                    [
                        'id' => 'my-membership',
                        'label' => 'My Membership',
                        'route' => 'my-membership',
                        'icon' => 'identification',
                        'contexts' => [self::CONTEXT_SHOOTER],
                        'roles' => null,
                        'current_route_is' => ['my-membership', 'membership.certificate', 'membership.activity-report'],
                    ],
                    [
                        'id' => 'family',
                        'label' => 'My Family',
                        'route' => 'family.index',
                        'icon' => 'users',
                        'contexts' => [self::CONTEXT_SHOOTER],
                        'roles' => null,
                        'current_route_is' => ['family.*'],
                        'badge' => 'family_count',
                        'badge_color' => 'emerald',
                    ],
                ],
            ],
            [
                'key' => 'equipment',
                'heading' => 'Equipment & Reloading',
                'contexts' => [self::CONTEXT_SHOOTER],
                'expandable' => false,
                'items' => [
                    [
                        'id' => 'rifles',
                        'label' => 'My Rifles',
                        'route' => 'rifle-configurations.index',
                        'icon' => 'wrench-screwdriver',
                        'contexts' => [self::CONTEXT_SHOOTER],
                        'roles' => null,
                        'current_route_is' => ['rifle-configurations.*'],
                    ],
                    [
                        'id' => 'ammo',
                        'label' => 'My Ammo',
                        'route' => 'ammo-loads.index',
                        'icon' => 'fire',
                        'contexts' => [self::CONTEXT_SHOOTER],
                        'roles' => null,
                        'current_route_is' => ['ammo-loads.*'],
                    ],
                    [
                        'id' => 'barrels',
                        'label' => 'My Barrels',
                        'route' => 'barrels.index',
                        'icon' => 'cube',
                        'contexts' => [self::CONTEXT_SHOOTER],
                        'roles' => null,
                        'current_route_is' => ['barrels.*'],
                    ],
                    [
                        'id' => 'ladder',
                        'label' => 'Ladder Analyser',
                        'route' => 'ladder-sessions.index',
                        'icon' => 'chart-bar',
                        'contexts' => [self::CONTEXT_SHOOTER],
                        'roles' => null,
                        'current_route_is' => ['ladder-sessions.*'],
                    ],
                    [
                        'id' => 'strings',
                        'label' => 'String Analyser',
                        'route' => 'ammo-strings.index',
                        'icon' => 'beaker',
                        'contexts' => [self::CONTEXT_SHOOTER],
                        'roles' => null,
                        'current_route_is' => ['ammo-strings.*'],
                    ],
                ],
            ],
            [
                'key' => 'match_management',
                'heading' => 'Match Management',
                'contexts' => [self::CONTEXT_ADMIN],
                'expandable' => true,
                'items' => [
                    [
                        'id' => 'manage-matches',
                        'label' => 'Manage Matches',
                        'route' => 'matches.index',
                        'icon' => 'cog-6-tooth',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin', 'match_director'],
                        'current_route_is' => ['matches.*'],
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
                        'id' => 'score-imports',
                        'label' => 'Score Imports',
                        'route' => 'score-imports.index',
                        'icon' => 'arrow-up-tray',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin', 'match_director'],
                        'current_route_is' => ['score-imports.*'],
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
                ],
            ],
            [
                'key' => 'federation',
                'heading' => 'Federation',
                'contexts' => [self::CONTEXT_ADMIN, self::CONTEXT_SHOOTER],
                'expandable' => true,
                'items' => [
                    $rulesAndDocuments,
                    array_merge($communications, [
                        'contexts' => [self::CONTEXT_SHOOTER],
                    ]),
                    $iprf,
                    [
                        'id' => 'memberships',
                        'label' => 'Memberships',
                        'route' => 'memberships.index',
                        'icon' => 'users',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'chair', 'owner', 'admin'],
                        'current_route_is' => ['memberships.*'],
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
                        'label' => 'Provincial Committees',
                        'route' => 'provincial-committees.index',
                        'icon' => 'building-library',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner'],
                        'current_route_is' => ['provincial-committees.*'],
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
                        'id' => 'selection-cycles',
                        'label' => 'Selection Cycles',
                        'route' => 'selection.cycles.index',
                        'icon' => 'flag',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin', 'iprf_selector'],
                        'current_route_is' => ['selection.*'],
                    ],
                    [
                        'id' => 'national-team',
                        'label' => 'National Team & Colours',
                        'route' => 'national-team.index',
                        'icon' => 'star',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin'],
                        'current_route_is' => ['national-team.*'],
                    ],
                ],
            ],
            [
                'key' => 'communications',
                'heading' => 'Communications',
                'contexts' => [self::CONTEXT_ADMIN],
                'expandable' => true,
                'items' => [
                    $communications,
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
                        'label' => 'Contact Enquiries',
                        'route' => 'contact-messages.index',
                        'icon' => 'envelope',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'chair', 'owner', 'admin'],
                        'current_route_is' => ['contact-messages.*'],
                        'badge' => 'unhandled_contacts',
                        'badge_color' => 'amber',
                    ],
                ],
            ],
            // ExCo workspace: meetings, action items, disciplinary case
            // register. Restricted at the item level to developer / exco /
            // chair — owner and admin do not see these links even though
            // they are otherwise senior staff. Matches the route middleware.
            [
                'key' => 'exco',
                'heading' => 'ExCo',
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
                ],
            ],
            [
                'key' => 'reports',
                'heading' => 'Reports',
                'contexts' => [self::CONTEXT_ADMIN],
                'expandable' => true,
                'items' => [
                    [
                        'id' => 'reports-hub',
                        'label' => 'Reports Hub',
                        'route' => 'reports.index',
                        'icon' => 'chart-bar',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin'],
                        'current_route_is' => ['reports.index'],
                    ],
                    [
                        'id' => 'reports-sponsorship',
                        'label' => 'Sponsorship',
                        'route' => 'reports.sponsorship',
                        'icon' => 'megaphone',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin'],
                        'current_route_is' => ['reports.sponsorship'],
                    ],
                    [
                        'id' => 'reports-selection',
                        'label' => 'Selection',
                        'route' => 'reports.selection',
                        'icon' => 'trophy',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin'],
                        'current_route_is' => ['reports.selection'],
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
                        'id' => 'provincial-members',
                        'label' => 'Provincial Members',
                        'route' => 'provincial-members.index',
                        'icon' => 'users',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin', 'provincial_admin'],
                        'current_route_is' => ['provincial-members.*'],
                    ],
                    [
                        'id' => 'sascoc-report',
                        'label' => 'SASCOC Report',
                        'route' => 'sascoc-report.index',
                        'icon' => 'document-chart-bar',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['exco', 'owner'],
                        'current_route_is' => ['sascoc-report.*'],
                    ],
                ],
            ],
            [
                'key' => 'finance',
                'heading' => 'Finance',
                'contexts' => [self::CONTEXT_ADMIN],
                'expandable' => true,
                'items' => [
                    [
                        'id' => 'financials-dashboard',
                        'label' => 'Dashboard',
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
                        'id' => 'financials-transactions',
                        'label' => 'Transactions',
                        'route' => 'financials.transactions',
                        'icon' => 'queue-list',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin'],
                        'current_route_is' => ['financials.transactions'],
                    ],
                ],
            ],
            [
                'key' => 'administration',
                'heading' => 'Administration',
                'contexts' => [self::CONTEXT_ADMIN],
                'expandable' => true,
                'items' => [
                    [
                        'id' => 'user-management',
                        'label' => 'User Management',
                        'route' => 'user-management.index',
                        'icon' => 'user-group',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner'],
                        'current_route_is' => ['user-management.*'],
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
                        'label' => 'Sponsor Tiers',
                        'route' => 'sponsor-tiers.index',
                        'icon' => 'tag',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner'],
                        'current_route_is' => ['sponsor-tiers.*'],
                    ],
                    [
                        'id' => 'qualification-rules',
                        'label' => 'Qualification Rules',
                        'route' => 'qualification-rules.index',
                        'icon' => 'cog-6-tooth',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner'],
                        'current_route_is' => ['qualification-rules.*'],
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
                        'id' => 'fees',
                        'label' => 'Membership Fees',
                        'route' => 'fees.index',
                        'icon' => 'banknotes',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner'],
                        'current_route_is' => ['fees.*'],
                    ],
                    [
                        'id' => 'site-settings',
                        'label' => 'Site Settings',
                        'route' => 'site-settings.index',
                        'icon' => 'adjustments-horizontal',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner'],
                        'current_route_is' => ['site-settings.*'],
                    ],
                    [
                        'id' => 'audit-logs',
                        'label' => 'Audit Logs',
                        'route' => 'audit-logs.index',
                        'icon' => 'document-magnifying-glass',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin'],
                        'current_route_is' => ['audit-logs.*'],
                    ],
                    [
                        'id' => 'email-logs',
                        'label' => 'Email Log',
                        'route' => 'email-logs.index',
                        'icon' => 'envelope',
                        'contexts' => [self::CONTEXT_ADMIN],
                        'roles' => ['developer', 'exco', 'owner', 'admin'],
                        'current_route_is' => ['email-logs.*'],
                    ],
                ],
            ],
        ];
    }

    /**
     * Sections visible to this user in this view mode, with hrefs / current
     * / badges resolved for the current request.
     *
     * @return list<array{key: string, heading: string, expandable: bool, expanded: bool, badge: int|null, badge_color: string, items: list<array<string, mixed>>}>
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
                    'current' => $current,
                    'badge' => self::resolveBadge($item['badge'] ?? null, $user),
                    'badge_color' => $item['badge_color'] ?? 'emerald',
                ];
            }

            if ($items === []) {
                continue;
            }

            [$sectionBadge, $sectionBadgeColor] = self::aggregateSectionBadge($items);

            // Shooter Federation is three flat links — keep it open.
            // Admin Federation (and other large admin groups) collapse
            // unless they contain the active route.
            $expandable = $section['expandable'] && $viewMode === self::CONTEXT_ADMIN;

            $resolved[] = [
                'key' => $section['key'],
                'heading' => $section['heading'],
                'expandable' => $expandable,
                'expanded' => $expandable && $sectionHasCurrent,
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

        // Only the group that owns the active route stays expanded.
        return array_map(function (array $section) use ($activeSectionKey): array {
            if ($section['expandable'] && $section['key'] !== $activeSectionKey) {
                $section['expanded'] = false;
            }

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
     * @return array{id: string, label: string, route: string, icon: string, contexts: list<string>, roles: null, current_route_is: list<string>}
     */
    private static function rulesAndDocumentsItem(): array
    {
        return [
            'id' => 'documents',
            'label' => 'Rules & Documents',
            'route' => 'documents.index',
            'icon' => 'document-text',
            'contexts' => [self::CONTEXT_ADMIN, self::CONTEXT_SHOOTER],
            'roles' => null,
            'current_route_is' => ['documents.*', 'selection.policy.public', 'legal.*', 'rules.*'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function communicationsItem(): array
    {
        return [
            'id' => 'communications',
            'label' => 'Communications',
            'route' => 'communications.index',
            'icon' => 'bell',
            'contexts' => [self::CONTEXT_ADMIN, self::CONTEXT_SHOOTER],
            'roles' => null,
            'current_route_is' => ['communications.*'],
            'badge' => 'communications_unread',
            'badge_color' => 'emerald',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function iprfItem(): array
    {
        return [
            'id' => 'iprf',
            'label' => 'IPRF',
            'route' => 'iprf.index',
            'icon' => 'flag',
            'contexts' => [self::CONTEXT_ADMIN, self::CONTEXT_SHOOTER],
            'roles' => null,
            'current_route_is' => ['iprf.*'],
        ];
    }

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
        $value = match ($badge) {
            'family_count' => $user->managedAccounts()->count(),
            'communications_unread' => AnnouncementRecipient::query()
                ->where('user_id', $user->id)
                ->whereNull('read_at')
                ->count(),
            'pending_approvals' => ApprovalController::totalPendingCount(),
            'unhandled_contacts' => ContactMessage::query()->clean()->unhandled()->count(),
            'pending_md_payouts' => Payout::query()
                ->where('payee_type', 'match_director')
                ->where('status', 'pending')
                ->count(),
            default => 0,
        };

        return $value > 0 ? $value : null;
    }

    /**
     * Sum child item badges onto the section heading. Amber wins when any
     * child uses it (e.g. unhandled contact enquiries).
     *
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
