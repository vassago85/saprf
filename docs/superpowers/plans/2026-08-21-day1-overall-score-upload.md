# Day 1 / Overall Score Upload Implementation Plan

> **For agentic workers:** Implement task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let MDs upload Day 1 (sibling provincial) or Overall (national) CSVs for 2-day nationals; totals-only CSVs work for all match types.

**Architecture:** Add `source_national_match_id` on matches. Score-import form posts `score_scope` (`day1`|`overall`). Controller finds/creates the provincial sibling for Day 1, then queues import onto the resolved match with `day` null (single-total path).

**Tech Stack:** Laravel 12, Livewire/Blade, Pest

## Global Constraints

- National scores never feed provincial standings
- Stages optional on every import
- Sibling naming: `{National name} — Provincial (Day 1)`
- Sibling has `everyone_counts = true`

---

### Task 1: Migration + model link

- [x] Add nullable `source_national_match_id` FK on `matches`
- [x] Add fillable + `sourceNationalMatch()` / `provincialDay1Match()` on `MatchEvent`
- [x] Add `findOrCreateProvincialDay1Sibling()` helper on `MatchEvent`

### Task 2: Import controller + validation

- [x] Replace `day` 1/2 form field with `score_scope` for 2-day nationals
- [x] Resolve target match (sibling vs national) before creating `ScoreImport`
- [x] Scope `replace_existing` to the target match only (and clear shooter_logs first)
- [x] Pass `is_two_day_national` in matchMeta

### Task 3: UI

- [x] Update `score-imports/create.blade.php` to Day 1 / Overall cards
- [x] Clarify CSV help: totals-only OK; stages optional

### Task 4: Tests

- [x] Day 1 upload creates sibling and imports onto it
- [x] Second Day 1 upload reuses same sibling
- [x] Overall upload writes to national only
- [x] Replace Day 1 does not wipe national scores
- [x] Totals-only CSV (no stages) imports for 1-day match
