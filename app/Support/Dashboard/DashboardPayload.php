<?php

namespace App\Support\Dashboard;

/**
 * Shared shape for every role dashboard shell.
 *
 * @phpstan-type Action array{label: string, href: string, variant?: string}
 * @phpstan-type Attention array{count: int, label: string, href: string, tone?: string}
 * @phpstan-type Kpi array{label: string, value: string, href?: string|null, hint?: string|null, progress?: float|null, accent?: string|null}
 * @phpstan-type Panel array{title: string, href?: string|null, href_label?: string|null, rows: list<array<string, mixed>>}
 * @phpstan-type Footer array{title: string, href?: string|null, href_label?: string|null, items: list<array{label: string, value: string, badge?: string|null}>}
 */
final class DashboardPayload
{
    /**
     * @param  list<Action>  $actions
     * @param  list<Attention>  $attention
     * @param  list<Kpi>  $kpis
     * @param  Panel|null  $primary
     * @param  Panel|null  $secondary
     * @param  Footer|null  $footer
     */
    public function __construct(
        public readonly string $greeting,
        public readonly string $title = 'Dashboard',
        public readonly ?string $season = null,
        public readonly bool $showSeasonSelect = false,
        public readonly array $actions = [],
        public readonly array $attention = [],
        public readonly array $kpis = [],
        public readonly ?array $primary = null,
        public readonly ?array $secondary = null,
        public readonly ?array $footer = null,
    ) {}
}
