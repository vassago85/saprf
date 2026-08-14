# Qualification & Season Points — Plain-English Guide

A shooter-facing explainer for what the **Qualification Progress** panel on the
member dashboard is showing, how the numbers are calculated, and how it all
adds up on the season standings page.

This document is the "how it's measured and articulated" guide. The
authoritative selection rules live in
[`docs/selection/pr22/2027/policy.md`](selection/pr22/2027/policy.md) and
[`docs/selection/prs/2026/policy.md`](selection/prs/2026/policy.md) — if this
guide ever contradicts those, the policy wins.

---

## 1. What the dashboard shows

When you log in as a member, the **Qualification Progress** card lists every
active discipline (currently **PR22 Rimfire** and **PRS Centrefire**) side by
side. Each card has:

- A **description** of the scoring model in one sentence.
- One row per **process step** with progress in the form `completed / required`.
- An **out-of-province nationals** requirement below the steps (finals
  eligibility).
- A **"FINALS ELIGIBLE"** badge when both the OOP requirement is met and every
  step is at least started.

The two disciplines use different scoring models. PR22 uses **Weighted Pools**;
PRS uses an **Annual Log + Champs** model. That's why they look different.

---

## 2. PR22 (Rimfire) — Weighted Pools

Your season total for PR22 is a **weighted average out of 100**, computed from
three separate pools of matches:

| Pool | What counts | Best of | Weight | Divisor rule |
|---|---|---|---|---|
| Provincial pool | Every **provincial-level** PR22 match | 3 | **30 %** | Strict — divides by 3 even if you shot fewer |
| National pool | Every **national-level** PR22 match | 2 | **40 %** | Gate: must shoot at least 2 nationals to score any national points; then best 2, no drop |
| SA Champs | The **final-level** PR22 championship | 1 | **30 %** | Strict — divides by 1 |

Each pool works independently:

1. Take the shooter's normalised % from every eligible match in the pool
   (normalised = raw score ÷ top raw score of the day × 100).
2. Sort highest to lowest.
3. Take the top `best_of` scores and average them, dividing by `best_of`
   (missing matches count as 0).
4. Multiply by the pool weight → **pool contribution**.
5. Sum all three contributions → **season total out of 100**.

### Worked example (from the dashboard screenshot)

The card in the screenshot shows a shooter with:

- **Provincial pool: 8/3** — they've shot 8 provincials, only the best 3
  count. Pool contribution can now be at its max (30 × best 3 / 3 = 30 %).
- **National pool: 2/3** — they've shot 2 nationals. The dashboard shows
  `2/3` because the counter target is `best_of + 1 = 3` (a legacy display
  convention), but the calculation itself takes the best 2 nationals with no
  drop. The `min matches` gate (2) is satisfied, so both count.
- **SA Champs: 0/1** — they haven't shot Champs yet. That pool contributes 0.
- **Out-of-province nationals: 1/1 Met** — they've done the mandatory
  travel-national requirement, so **FINALS ELIGIBLE** lights up.

Their season standing is calculated from whichever of those matches count,
weighted 30 / 40 / 30.

### Why "drop worst" appears in the description

Historically the national pool used a drop-one rule (shoot N+1, drop worst).
The system now uses a **min-matches gate + best-of** instead (see
`aggregateWeightedPools()` in `StandingsCalculationService`), but the label
`drop worst` remains on the dashboard as shorthand for "you need to shoot
extras before scores start counting". If in doubt, the rule that ships with
the code is authoritative.

---

## 3. PRS (Centrefire) — Annual Log + Champs

PRS uses a simpler model: your season total is your **best 3 national match
percentages + your SA Champs percentage**. Maximum possible = 300 + 100 = 400.

| Component | What counts | Required | Notes |
|---|---|---|---|
| Regular nationals | Every **national-level** PRS match | Best 3 of your matches | Any extras beyond 3 are dropped |
| SA Champs | The **final-level** PRS championship | 1 | **Non-droppable** — 0 if you didn't shoot it |

The Champs component cannot be replaced by a good regular national — a shooter
with four 100 % regulars and no Champs tops out at 300, not 400.

Provincial PRS matches don't feed the national annual log. They have their own
separate provincial standing (sum of the best 3 provincial normalised %s per
province).

---

## 4. Out-of-province nationals (both disciplines)

Selection for a National Team requires you to have travelled — you can't just
shoot in your home province. The rule is:

> To be **finals eligible**, you must complete at least the configured minimum
> number of **national** matches held **outside your home province**.

The dashboard shows this as `1/1 Met` or `0/1 Not yet`. Provincial matches and
national matches in your own province don't count towards this requirement.

If the OOP requirement is not met, you can still shoot and accumulate season
points — you just can't be selected for a national team.

---

## 5. How individual scores are validated

A score is only added to your season standing if the platform stamps it
**valid**. The stamp happens automatically when the score is imported:

- **valid** — you were an **active + paid** SAPRF member on match day. Only
  valid scores feed the season log.
- **pending** — score exists but membership couldn't be confirmed yet
  (usually because payment is still processing). Shown on the match page but
  doesn't count until it resolves.
- **lapsed** — your membership had expired on match day. Score is visible on
  the match page but is ignored by the season log.
- **non_member** — you weren't a SAPRF member on match day. Score is visible
  (non-members can win a match), but doesn't count towards a season total.

Membership status is captured **at the moment the score is imported**, not
re-evaluated later. This means:

- If your membership was current on match day and you let it lapse three
  months later, your existing valid scores stay valid.
- Conversely, backdating a payment won't retroactively promote a
  `non_member` score to `valid` — a manual reconciliation step is available
  to admins for that.

See `ScoreValidationService::evaluateScoreStatus()` for the exact logic.

---

## 6. Where the numbers live

Everything above is driven by three pieces of code and one config table:

| Where | What it does |
|---|---|
| `qualification_rules` table | Per-series, per-season configuration: pool best-of counts, weights, min matches, OOP requirement. Editable via **Settings → Qualification Rules**. |
| `App\Services\QualificationService` | Builds the dashboard's progress panel — reads `qualification_rules`, counts a shooter's valid scores by level, returns the step list. |
| `App\Services\StandingsCalculationService` | Recomputes season points after every score import or match edit. Writes to the `standings` table (rank + points + full breakdown). |
| `App\Services\ScoreValidationService` | Stamps each score `valid` / `pending` / `lapsed` / `non_member` when it's imported. |

If you want to see exactly how a specific shooter's points were built, open
their entry in the standings table — the `pool_breakdown` JSON column stores
the per-match contribution for every counted score.

---

## 7. Common questions

**Q. Why does the National pool show `2/3` when the rule says best-of-2?**
The `+1` on the display is a legacy convention that used to mean "shoot one
extra to drop your worst". The current national-pool rule is a min-matches
gate (default 2) plus best-of-N (default 2), no drop. Everyone shooting 2 or
more nationals gets both scores counted.

**Q. I shot 8 provincials — do I get credit for all 8?**
No. Only your best 3 count towards the season total. The other 5 are visible
on your profile but don't add points. Shooting extras still improves your
chance that your best-3 average is high.

**Q. I didn't shoot Champs. Can I still qualify?**
- **PR22**: your pool contribution is 0 × 30 % = 0. You lose 30 % of the
  possible season total but can still accumulate provincial + national
  points and appear on the standings.
- **PRS**: your Champs component is 0. You can still be ranked; you just top
  out at 300 out of a possible 400.
- **Finals selection**: consult the policy document for the discipline. In
  general Champs is expected but not always mandatory.

**Q. I'm out of province — do I still shoot in my home provincial standing?**
Yes. Provincial standings follow the **shooter's home province**, not the
match host province. So if you travel to another province for a provincial
match, that score counts towards your OWN provincial table, not the host's.

**Q. What if my membership lapses mid-season?**
Any score you posted while you were paid up stays `valid` and keeps counting.
Any score you post after your expiry date is stamped `lapsed` and doesn't
count until you renew. Renew before the match and every score in that season
is safe.

**Q. Where can I see exactly which matches counted for me?**
On your public shooter profile at `/standings/shooters/<slug>` — the match
list shows a `Counted` badge and a per-match contribution column for every
score in the season. That's the same data used to build your standing rank.

---

## 8. Change history

- **v1.0 — 14 Aug 2026**: Initial draft, based on `QualificationService`,
  `StandingsCalculationService`, `ScoreValidationService`, and
  `QualificationRule` as they exist at commit `b8d36f3`.

If you spot a discrepancy between this guide and the app, file it as a bug —
the code is the source of truth.
