# Day 1 / Overall Score Upload Design

**Date:** 2026-08-21  
**Status:** Approved

## Problem

2-day nationals need day-1 results to count toward shooter logs and provincial standings, but a national match must never feed the provincial pool. MDs also often have CSVs with totals only (no stage columns).

## Decision

On score upload for a **2-day national**, offer two scopes:

| Scope | Target match | CSV content |
|---|---|---|
| **Day 1 scores** | Auto sibling provincial | Day-1 totals (stages optional) |
| **Overall scores** | The national itself | Full match totals (stages optional) |

1-day matches (provincial or national) keep a single upload with `shooter_name` + `raw_score`; stages remain optional.

## Sibling provincial (Day 1)

When uploading Day 1 against a 2-day national:

1. Find existing sibling via `matches.source_national_match_id`, or create one:
   - Name: `{National name} — Provincial (Day 1)`
   - `series_level = provincial`
   - Same series, season, province, venue, city, director, match_date (start date only; no end date)
   - `everyone_counts = true`
   - `status = completed`, `published = true`
   - `source_national_match_id` → national id
2. Import CSV onto the sibling only (`day` null — single-day total path).
3. National is unchanged until Overall is uploaded.

Replace-existing on Day 1 clears only sibling scores. Replace on Overall clears only national scores.

## CSV requirements

Required: shooter identity (`shooter_name` or first+last) and a numeric total (`raw_score` / Impacts / Total / etc.).  
Stage columns are never required. This applies to single-day and both 2-day scopes.

## Out of scope

- Separate Day 2-only upload option
- Reviving `also_counts_for_provincial` dual-count into provincial standings
- Auto-backfill of historical Darling/Clash siblings (existing artisan command remains)
