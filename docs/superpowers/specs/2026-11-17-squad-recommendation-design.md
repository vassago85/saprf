# Squad recommendation for Impact

**Date:** 2026-11-17
**Status:** Approved in chat, pending spec review

## Problem

SA Champs squadding is by overall ranking. With a fixed number of squads the field is split evenly: 5 squads and 55 shooters is 11 each. Ladies get one of those squads when they can fill it, and the same for juniors. When neither group can fill a squad, they share one, and empty places go to shooters whose rank sits between them. The match director needs to see that recommendation and download it as an Impact shooter file with the Squad column filled in.

The existing Impact export leaves Squad blank on purpose. A normal import must keep doing that.

## Decision

On the match Entries page, the director types how many squads, sees the recommendation, and downloads a separate Impact CSV. The preview is not stored. The public squadding card is unchanged. The existing Export for Impact link stays blank.

## Where it lives

Both actions sit in the match workspace group, next to `matches.export-impact-scoring`, and use the same gate: middleware `developer|exco|owner|admin|match_director` and `$this->authorize('update', $match)`.

- `matches.squad-recommendation` — GET. Query `squads` (required integer). Renders the Entries page with the preview above the entry list.
- `matches.squad-recommendation.export` — GET. Same `squads` query. Streams the CSV.

The form is on `resources/views/matches/workspace/entries.blade.php`, beside Export for Impact. Fields: squad count, Recommend, and Download for Impact. Download is shown only after a preview for that same count.

## Who is squadded

Non-cancelled registrations on the match. Cancelled rows are ignored.

Rank comes from the overall season standing for the match's `series` and `season`: `standings.province_id` is null and `standings.division_id` is null, matched on `user_id`. A lower rank number is better. A registration with no user, or no standing row, is unranked.

Sort order for ranking work:

1. Ranked shooters by rank number, then by name.
2. Unranked shooters by name.

A shooter is a lady when the registration division slug is `ladies`, and a junior when it is `junior`. Any other division stays in the ranking pool. One registration has one division, so a shooter is never both.

## Squad sizes

`n` is the number of shooters. `N` is the requested squad count.

Reject the request when `N` is missing, `N < 1`, `N > n`, or `n` is 0. Show the message on the Entries page and do not build a file.

`small = floor(n / N)`. When `n` is divisible by `N`, every squad has `small` shooters. Otherwise `n % N` squads have `small + 1` and the rest have `small`. Sizes differ by at most one.

A ladies group or a juniors group can take a squad when its count is at least `small`. The shared ladies-and-juniors group uses that same test on the combined count.

## Category squads

Skip the category split when `N` is 1, or when every shooter is already inside the group that would be peeled off (the whole field is ladies, the whole field is juniors, or the whole field is ladies and juniors and they are sharing). In those cases, split the whole field by the sort order above.

Otherwise:

- Ladies take one squad when they have at least `small` shooters.
- Juniors take one squad when they have at least `small` shooters.
- When neither group reaches `small`, and there is at least one lady or junior, they share one squad.
- When one group reaches `small` and the other does not, only the group that reaches it gets a squad. The short group goes into the ranking pool.
- At most one squad per category, and at most two category squads. Leftover ladies and leftover juniors go into the ranking pool.

Give a category squad size `small + 1` when a large slot remains and the group has at least that many shooters. Otherwise its size is `small`. Large slots left after the category squads go to the ranking squads that contain the best ranks.

When a category group is bigger than its squad, keep the best-ranked members and return the rest to the ranking pool. Unranked members of that group are returned first. Ties on rank use name.

When a category group is smaller than its squad, fill the empty places from the ranking pool:

1. Shooters with a rank strictly between the best and worst rank already in the squad. If more qualify than there are places, take those closest to the median of the ranks already in the squad. For an even count, the median is the average of the two middle ranks. Equal distance keeps the better rank, then the name.
2. If the squad is still short, take the remaining ranked shooter closest to that same median, one at a time, with the same tie-break.
3. If no ranked shooter remains, take unranked shooters by name.

Someone already placed in a category squad is not a filler.

## Ranking squads and numbers

Split the ranking pool across the remaining slots by the sort order above, best ranks first. Slot sizes are the sizes not used by category squads.

Number every squad by its best rank, so a ladies, juniors, or shared squad stays where that rank sits. Ranking squads on either side stay in rank order: the ranking squad after one that ends on rank 25 starts at rank 26 and continues downward.

The match director can move a shooter to another squad from the preview. Apply regroups the list. Download for Impact uses the squad chosen on each row. Recommend again clears those moves and rebuilds from rank.

## Preview

The preview is grouped by squad number. Each row shows the shooter name, the rank number or "No rank", and the division name.

Under the groups, list two warnings when they apply:

- How many shooters have no overall rank. They were placed after the ranked field.
- Which shooters the Impact file will skip because they have no email address. The skip rule is the one already in `ImpactShooterExport`: a registration with no email at all is omitted, and a shared email is kept by the account that owns it.

## Download

The file uses the same columns and the same row rules as `ImpactShooterExport`: Email, First Name, Last Name, Phone, Squad, Division, Categories, Member Number, Shooter Number.

Squad is the squad number as digits (`1`, `2`, `3`). Every shooter who appears in the current Impact export appears here, with that number. Shooters the current export omits are omitted here too.

Filename: `{match-slug}-impact-squads.csv`.

The existing `matches.export-impact-scoring` response is unchanged, including an empty Squad column.

## Tests

- 55 ranked shooters and 5 squads, no ladies or juniors: five squads of 11, squad 1 holds ranks 1–11, squad 5 holds ranks 45–55.
- 6 ladies and 3 juniors inside a larger ranked field, 5 squads: they share one squad, and the empty places are the shooters closest to the middle of that rank span.
- 14 ladies and a squad size of 11: the ladies squad keeps the 11 best, and the 3 lowest ladies sit in ranking squads.
- Ladies reach `small` and juniors do not: juniors are in the ranking pool, not in the ladies squad.
- An unranked shooter is after every ranked shooter.
- The CSV Squad column matches the preview, and `matches.export-impact-scoring` still writes an empty Squad column.
- `squads` of 0, or greater than the field, returns the Entries page with an error and no file.
