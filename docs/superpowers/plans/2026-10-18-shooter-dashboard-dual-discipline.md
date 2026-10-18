# Shooter Dashboard Dual-Discipline Implementation Plan

> **For agentic workers:** Implement task-by-task. Spec is the user brief + `Downloads/Shooter dashboard · dual discipline-html/`.

**Goal:** Replace the shooter `/dashboard` body with the dual-discipline layout (identity, next matches + attention, per-discipline season cards, recent results, rifles strip). Sidebar and staff dashboards untouched.

**Architecture:** Livewire Volt (or class) component with `#[Url] nextFilter` / `resultsFilter`; query helpers under `App\Support\Dashboard\Shooter\*`; thin `dashboard.member` wraps shell banner + Livewire.

**Stack:** Laravel 12, Livewire 4 / Volt, Tailwind, existing formatters.

## Files

| Path | Role |
|---|---|
| `app/Support/Dashboard/Shooter/DisciplineScope.php` | Disciplines from scores this/last season (or both if none) |
| `app/Support/Dashboard/Shooter/NextMatchesBuilder.php` | Upcoming + 3/4-row rule + status + finals pill |
| `app/Support/Dashboard/Shooter/AttentionBuilder.php` | OOP / bad scores / renewal / rifles |
| `app/Support/Dashboard/Shooter/SeasonCardBuilder.php` | Per-series card (ranks, level table, qualification UI) |
| `resources/views/livewire/dashboard/shooter-dashboard.blade.php` | Volt component + UI |
| `resources/views/dashboard/member.blade.php` | Banner + Livewire only |
| `app/Http/Controllers/DashboardController.php` | Stop building obsolete member payload body data |
| `tests/Feature/ShooterDashboardTest.php` | Cases (a)–(f) |

## Note on national points

Pooled scoring (PR22) includes provincial matches in the national standings pool by design (`StandingsCalculationService`). Show muted "Includes provincial points" under the National tile when `scoring_mode` is weighted pools.

## Tasks

1. Helpers + failing Pest stubs for discipline scope and next-match row count
2. Season card + attention builders (reuse QualificationService / ShooterStandingsSummaryService; display-only)
3. Volt Livewire UI matching mockup
4. Wire member view; drop old sections and shell KPIs for shooters
5. Full Pest (a–f) green
