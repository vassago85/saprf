# Dashboard Redesign Implementation Plan

> **For agentic workers:** Implement task-by-task. Steps use checkbox syntax.

**Goal:** Ship a shared mockup-aligned dashboard shell for every staff role and wrap the shooter dashboard, driven by role presenters.

**Architecture:** `DashboardPayload` DTO + Blade `x-dashboard.*` components; each role builds a presenter; `DashboardController` wires them.

**Tech Stack:** Laravel 12, Blade, Tailwind, existing money/date helpers, Pest.

## Global Constraints

- Title is always "Dashboard"
- Attention pills only when count > 0
- Money via `money()` / `money_delta()`
- Developer dashboard unchanged
- Shooter keeps existing body blocks below the shell
- No Livewire / charts in v1

## Files

| Path | Role |
|---|---|
| `app/Support/Dashboard/DashboardPayload.php` | Immutable payload DTO |
| `app/Support/Dashboard/Concerns/BuildsDashboardShell.php` | Shared helpers (greeting, upcoming matches, audit rows) |
| `app/Support/Dashboard/OwnerDashboardPresenter.php` | Owner + ExCo |
| `app/Support/Dashboard/AdminDashboardPresenter.php` | Admin |
| `app/Support/Dashboard/MatchDirectorDashboardPresenter.php` | MD |
| `app/Support/Dashboard/ProvincialAdminDashboardPresenter.php` | Provincial |
| `app/Support/Dashboard/MemberDashboardPresenter.php` | Shooter shell top |
| `resources/views/components/dashboard/*.blade.php` | Shell UI |
| `resources/views/dashboard/*.blade.php` | Thin role views |
| `app/Http/Controllers/DashboardController.php` | Wire presenters |
| `tests/Feature/DashboardShellTest.php` | Feature coverage |

## Tasks

1. Payload + shell components + failing feature test
2. Owner presenter + view
3. Admin / MD / Provincial presenters + views
4. Member shell wrap
5. Controller wire + full test pass + commit
