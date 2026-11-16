# Day 1 provincial from live scores

**Date:** 2026-11-14
**Status:** Approved

## Problem

Day 1 of a 2-day national is the provincial for that weekend. The match director runs Impact live scoring on tablets. When every tablet has synced, they need to submit the day-1 stage totals as the provincial, then keep shooting day 2 so those same hits stay inside the combined national.

A national score never feeds provincial standings or federation colours. Live sync stores Impact's running total on the national and holds it off the season until the national is marked complete. That running total is both days once day 2 starts, so it must not be copied onto the provincial.

Impact stage names cannot be trusted. A match may be Stage 1 through Stage 16, with no "Day 1" or "Day 2" in the name, and only the first eight are day 1.

## Decision

On a 2-day national that is pulling Impact, the match director confirms which stages are day 1 and which are day 2, then submits. The submit copies each linked shooter's day-1 stage sum onto a provincial sibling. Live scoring on the national keeps running. Federation colours keep their current formula and pick the result up from provincial standings.

## Where it lives

The control sits on the live-scores panel of the match workspace scores page (`matches.scores`). It is visible when all of these are true:

- The viewer can update the match.
- The match is a 2-day national (`isTwoDayNational()`).
- Impact live sync is on and an Impact match id is set.
- The national scores are not marked complete.

It is not added to the Import from Impact screen.

Two routes, both inside the existing match workspace group, with these full names:

- `matches.scores.day1-provincial.preview` — pull the current stage list and shooter stage hits for the confirm panel.
- `matches.scores.day1-provincial.store` — save the stage marks and write the provincial.

Both accept POST. A prefetch must not be able to write scores.

## Confirming the stages

Opening the panel pulls Impact once and lists every stage in the order Impact returns them, numbered 1, 2, 3, and so on. The stage name is a label only. It is not used to choose the day.

Each stage starts unset the first time it is seen. The director marks it with two actions: the first mark sets **Day 1**, the next mark sets **Day 2**, and a further mark swaps it back to Day 1. A stage cannot be dropped. After the first mark it is always Day 1 or Day 2.

**Submit day 1 as the provincial** stays off while any stage is still unset, and while every stage is Day 2. At least one stage must be Day 1.

The marks are stored on the national as JSON, `matches.impact_stage_days`, a map of Impact stage id to `1` or `2`. Opening the panel again restores that map.

- A stage id that is no longer in the pull is dropped from the map.
- A stage id the pull has never stored arrives unset and keeps submit off until it is marked.
- The display number follows Impact's current order. The saved mark follows the stage id, so a renamed stage keeps the day the director gave it.

The panel shows how many stages are on each day, how many are still unset, and how many linked shooters currently have a day-1 total from the preview pull. The preview count can go stale if a tablet syncs before submit. The server pull inside submit is the one that gets written, and the result message reports that count.

## What submit writes

Submit pulls Impact again. If that pull fails, it writes nothing, leaves the previous provincial scores in place, and leaves the saved stage marks in place. The director sees the error.

On success it saves the posted stage map, then finds or creates the provincial sibling with the existing `findOrCreateProvincialDay1Sibling()`. That helper is not changed. The sibling keeps the helper's current shape: name `{National name} — Provincial (Day 1)`, `series_level = provincial`, `everyone_counts = false`, same series, season, province, venue, and start date. This action does not set `also_counts_for_provincial` on the national.

Shooters are matched the same way live sync matches them: an exact entry-name match, then the director's `impact_live_name_map`. There is no fuzzy match, no walk-in, and no stub account.

For each matched shooter who has a linked user:

- A complete stage breakdown and a day-1 sum above zero: upsert one score on the sibling. The sum uses the same stage value as `ImpactLiveImportService::sumStages()` (`hits`, then `points`). `day1_raw_score` is that sum. `day2_raw_score` stays empty. The score observer sets `raw_score` from day 1. Division and shooter name come from the entry, the same way live sync does.
- A complete stage breakdown and a day-1 sum of zero: remove that user's score on the sibling, including the shooter log.
- No stage breakdown in this pull: do not create a score, do not delete an existing one, and do not substitute Impact's combined total. Name them in the result as not updated.

An Impact name with no linked entry is listed and not written. A sibling score whose user is not in this pull's matched set, and is not in the not-updated hold list, is removed. Each submit therefore replaces the provincial score list, except for shooters whose stage breakdown failed this time.

Sibling scores are saved with `counts_for_season` and `counts_for_log` true. Live sync's "hold until the national is marked complete" flag is not copied onto them. `ScoreValidationService::evaluateScoreStatus()` then runs. After the rows are saved, standings are recalculated for the sibling only.

The national score rows, `impact_live_sync`, and `impact_live_ended_at` are not changed. No shooter email and no scores-finalized notification is sent.

## Who counts for colours

Federation colours are unchanged. They already read provincial standings.

Standings include a sibling score only when `evaluateScoreStatus()` marks it `valid` and the shooter's membership type is eligible for standings. An active paid member on the match date counts. A lapsed shooter, a shooter still inside the renewal grace window (`pending`), and a non-member appear on the provincial result and do not count toward provincial standings or federation colours.

Marking the national scores complete at the end of day 2 remains the action that publishes the combined national. That combined total already includes day 1, because live sync stores Impact's running total. Submit does not add the day-1 sum onto the national a second time.

## Errors

| Situation | Result |
|---|---|
| Preview or submit pull fails | Nothing is written. Saved marks and the previous provincial stay. |
| Any posted stage is missing, or a current stage id is absent from the post | Submit is rejected. Nothing is written. |
| No stage is marked Day 1 | Submit is rejected. Nothing is written. |
| National scores are already complete | The control is hidden and the store route rejects the post. |
| Shooter has no stage breakdown | That shooter is listed and left unchanged. Everyone else is still written. |

## Tests

One feature test, with the Impact client faked, covers a 16-stage match:

- Marking stages 1–8 as Day 1 and 9–16 as Day 2 creates the provincial sibling and stores each linked member's day-1 sum there.
- The national combined total is unchanged, live sync stays on, and `impact_live_ended_at` stays empty.
- An unmatched Impact name is listed and gets no provincial score.
- A non-member is stored on the provincial result with a non-counting status.
- A second submit that marks only stages 1–7 as Day 1 rebuilds the provincial totals and still does not change the national.
- Submit is rejected when a stage is unset, and when every stage is Day 2.
- A failed pull leaves an existing provincial score untouched.

## Out of scope

- Changing the gold, silver, or bronze cutoffs.
- Guessing the day from the stage name, or pre-selecting the first half.
- Stopping live sync, emailing shooters, or marking the national complete.
- Creating walk-ins or stub users from unmatched Impact names.
- Copying Impact's combined running total onto the provincial.
