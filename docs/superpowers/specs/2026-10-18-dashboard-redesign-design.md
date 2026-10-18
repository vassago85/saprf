# Dashboard redesign — design

**Date:** 2026-10-18  
**Status:** Draft for review  
**Source mockup:** `Downloads/saprf-dashboard-mockup.html` (owner desktop)

## Decisions locked

| Decision | Choice |
|---|---|
| Scope | All role dashboards, including shooter |
| Job of the page | Mix — action strip on top, snapshot below |
| Visual language | Owner mockup (header, attention pills, KPI cards, two panels, optional footer) |
| Shell reuse | Same shell for every role; content swaps per role |
| Shooter depth | Keep most of today’s member content; wrap in the new shell |
| Approach | Shared Blade shell + role presenters |
| Charts / Livewire | Not in v1 |

## Goals

1. One recognisable dashboard pattern across Owner/ExCo, Admin, Match Director, Provincial Admin, Member, (Developer stays thin).
2. “What needs me?” is visible above the fold for staff.
3. Season health KPIs are clickable and use shared `money()` / count formatting.
4. Shooter experience improves without ripping out membership / standings / rifles / recent matches.

## Non-goals (v1)

- Sidebar information architecture rewrite (mockup sidebar “before/after” already partially shipped).
- New analytics charts or Chart.js.
- Livewire / Volt dashboards.
- Changing Financials Overview (separate page).
- Production data cleanup.

## Architecture

```
DashboardController::{role}Dashboard()
  → App\Support\Dashboard\{Role}DashboardPresenter::build(User): DashboardPayload
  → view('dashboard.{role}')
       → <x-dashboard.shell :payload="$payload">
            optional default slot (shooter body)
         </x-dashboard.shell>
```

### `DashboardPayload` (simple DTO / array shape)

- `title` — always `"Dashboard"` (matches mockup; not “Owner Dashboard”)
- `greeting` — first name
- `season` — string year (staff + shooter); season select only where useful (owner/exco/admin)
- `actions` — list of `{label, href, variant}` primary CTAs
- `attention` — list of `{count, label, href, tone}` (amber|red|neutral); omit empty
- `kpis` — up to 4 `{label, value, href?, hint?, progress?}`
- `primary` — panel `{title, href?, rows[]}` (upcoming events / matches)
- `secondary` — panel `{title, href?, rows[]}` (activity / queues)
- `footer` — optional pricing strip (owner/exco only)
- `body` — not in payload; Blade slot for shooter (and any role that needs custom below)

### Blade components

| Component | Responsibility |
|---|---|
| `x-dashboard.shell` | Composition: header → attention → kpis → panels → footer → slot |
| `x-dashboard.header` | Greeting, h1, season select, CTAs |
| `x-dashboard.attention` | Pill strip; renders nothing if empty |
| `x-dashboard.kpi-grid` | 2×2 / 4-col clickable cards |
| `x-dashboard.panel` | Card with title + list rows |
| `x-dashboard.pricing-strip` | Owner fee tiers + surcharges + rules link |

Reuse existing shared helpers: `money()`, `date_short()`, membership status component where rows need it.

## Per-role content

### Owner / ExCo (same presenter)

**Attention (examples):** pending approvals, unhandled contact enquiries, score imports to review, pending credit refunds, open disciplinary (if role can see), overdue ExCo actions.

**KPIs:**

1. Active members — `active of total` + progress bar + YoY delta when cheap to compute  
2. Lapsing in 30 days — count → memberships list filtered  
3. Season income — memberships + matches (reuse financial summary for season period) → financials overview  
4. Matches this season — completed / upcoming / scores count  

**Primary panel:** Upcoming events (next ~5 open/draft with date, entries, status)  
**Secondary panel:** Recent activity from audit log (last ~5)  
**Footer:** Pricing & rules strip (fee tiers, surcharges, qualification rules count) — replaces today’s large fee cards as the primary surface; “Manage Fees” / site settings links remain via strip actions.

**Header CTAs:** New announcement (if can), New event / Create match (if can).

### Admin

**Attention:** pending memberships, pending approvals, pending scores, pending MD payouts, unhandled contacts.  
**KPIs:** pending memberships, upcoming matches, pending scores, pending MD payout total (`money()`).  
**Primary:** Upcoming federation matches.  
**Secondary:** Recent audit log.  
**Footer:** none.

### Match Director

**Attention:** pending registrations on their matches, score imports for their matches, matches missing status / draft past date (if detectable cheaply).  
**KPIs:** my matches, upcoming, pending registrations, unpaid entries on my matches (if cheap).  
**Primary:** My upcoming matches.  
**Secondary:** Pending registrations list (short).  
**Footer:** none.

### Provincial Admin

**Attention:** none or province-scoped approvals if any.  
**KPIs:** province members, active in province, upcoming provincial matches, (optional) lapsed in province.  
**Primary:** Upcoming provincial matches.  
**Secondary:** Quick links (provincial members, SASCOC if allowed).  
**Footer:** none.

### Member (shooter)

**Shell top:**

- Attention: membership renewal due, unpaid registration, unread communications, entry credit available (pills instead of/as well as today’s banners where they duplicate).  
- KPIs: matches shot (season), standings rank (best national if any), qualification progress summary, account credit if > 0.  
- Header CTA: View public profile; Renew if needed.

**Body slot:** Keep existing member blocks below the shell — membership card, next match, season rankings, stats breakdown, rifles, recent matches. Do not rewrite those into panels in v1; only stop duplicating the same facts already shown as pills/KPIs.

### Developer

Keep diagnostic env/version page. Optional: wrap in shell with no attention/KPIs, or leave as-is. Prefer leave as-is to avoid noise.

## Data rules

- Season scoping matches existing owner season filter (`season` column or `match_date` year).
- Counts use `number_format` without forcing thin-space (money only uses U+202F). Optional later: shared `count()` helper — not required for v1.
- Attention pills only render when `count > 0`.
- All money via `money()` / `money_delta()`.
- Presenters must be unit/feature-testable without rendering full HTML where practical.

## Testing

- Feature: each role hits `/dashboard` and sees expected shell markers (title Dashboard, at least one KPI label for staff roles).
- Feature: owner sees pricing strip; member does not.
- Feature: empty attention strip does not leave an empty card.
- Keep existing `MemberDashboardRecentMatchesTest` green.
- Extend or add presenter unit tests for KPI math (active/total, lapsing window).

## Rollout

1. Ship shell + owner/exco first (highest value vs mockup).  
2. Admin, MD, provincial.  
3. Member wrap.  
4. Commit audit leftovers (`money_delta`, email-logs filter, cancelled badge) with or just before dashboard PR.

## Open questions resolved in this doc

- ExCo = owner presenter (yes).  
- Season select: owner/exco/admin only.  
- Shooter: shell + keep body (option A).
