<?php

namespace App\Support;

/**
 * Canonical AI prompts used by the ExCo workspace.
 *
 * Centralised here so the in-app "AI prompts" reference page, the
 * import textarea help panel, and any future export flow all render
 * the same text. Update the strings here and the whole app updates.
 *
 * All prompts are versioned via `AI-PROMPT vX` in the first line so
 * that if the schema shape changes we can spot stale copies floating
 * around in members' saved-prompts files.
 */
final class ExcoAiPrompts
{
    public const VERSION = 1;

    /**
     * Prompt that converts a plain-text meeting notice into the JSON
     * schema accepted by ExcoMeetingController::import().
     */
    public static function noticeToJson(): string
    {
        return <<<PROMPT
AI-PROMPT v1 — SAPRF ExCo meeting notice → JSON import

You are converting a SAPRF National Executive Committee (ExCo) meeting
notice into JSON for import into the SAPRF platform.

Rules:
1. Preserve item order and wording. Do NOT paraphrase agenda titles.
2. If the notice has sub-items (4.1, 4.2, 5.1 etc.), SPLIT each
   sub-item into its own top-level agenda item, unless a sub-item is
   clearly just a bullet under its parent. Splitting gives each
   decision its own minutes block.
3. Set "visibility" to "confidential" ONLY if the title or context
   clearly involves HR, disciplinary, or legally privileged matter.
   Everything else is "ordinary".
4. If the notice names a responsible person for an item (e.g. "AL",
   "PC", "All"), put it as the first line of the briefing prefixed
   "Responsible: ". Then a blank line, then the rest of the briefing.
5. Put the members / attendance list verbatim into
   meeting.attendance_notes.
6. "type" is "regular" unless the notice clearly says special,
   extraordinary, or emergency.
7. scheduled_at format: YYYY-MM-DD HH:MM in 24-hour SAST.
8. Output ONLY valid JSON — no markdown fences, no commentary, no
   trailing prose.

Schema:

{
  "meeting": {
    "title": "string",
    "type": "regular" | "special",
    "scheduled_at": "YYYY-MM-DD HH:MM",
    "location": "string",
    "attendance_notes": "string"
  },
  "agenda_items": [
    {
      "title": "string",
      "briefing": "string",
      "visibility": "ordinary" | "confidential"
    }
  ]
}

Here is the notice:

<paste notice here>
PROMPT;
    }

    /**
     * Prompt that turns a raw transcript (Happy Scribe, otter.ai,
     * hand-typed notes) into the structured per-item minutes block
     * that pastes straight into the Minutes textarea.
     */
    public static function transcriptToMinutes(): string
    {
        return <<<PROMPT
AI-PROMPT v1 — SAPRF ExCo transcript → structured minutes

You are drafting formal minutes for a SAPRF National Executive
Committee (ExCo) meeting. You will receive (a) the agenda for the
sitting and (b) my raw notes or a transcript (Happy Scribe / otter.ai
diarised text is common). Convert them into structured minutes that
can be pasted straight into the SAPRF platform, one block per agenda
item.

Rules:
1. Never invent facts, names, numbers, or decisions that are not in
   the source. If something is unclear, write [unclear] rather than
   guess.
2. Neutral, formal tone. No editorialising, no adjectives that were
   not used in the room.
3. Preserve exact wording of any motion or resolution, including who
   moved / seconded and the vote result.
4. Names: full name on first mention, surname only after.
5. If the source has generic speaker labels ("Speaker 1", "Speaker
   2") rather than real names, do NOT guess who spoke. Attribute
   statements to "the Chair", "the Secretary", "a member", etc.
   based on role context.
6. Confidential items (disciplinary, HR, legal): keep the record
   high-level. Do NOT include personal information that is not
   essential to the decision. Refer to a disciplinary case by its
   DC-YYYY-NNN reference where possible.
7. Actions must have an owner and a due date. Use "next meeting" if
   no specific date was set.

Output format — repeat this block for each agenda item, in the order
listed in the agenda:

---
## <n>. <agenda item title>

**Minutes**
<2–6 sentences summarising discussion and outcome. Include mover /
seconder for any motion and the vote result if there was one.>

**Decisions**
- <decision 1>
- <decision 2>
(omit this heading if no decisions were taken)

**Actions**
- <what> — Owner: <name> — Due: <YYYY-MM-DD or "next meeting">
- <what> — Owner: <name> — Due: <date>
(omit this heading if no actions were assigned)
---

At the end, add a short "Housekeeping" block listing:
- Apologies received
- Attendance changes mid-meeting
- Date of next meeting (if mentioned)

AGENDA
======
<paste your agenda item titles here, one per line, numbered>

RAW NOTES / TRANSCRIPT
======================
<paste your raw notes or transcript here>
PROMPT;
    }
}
