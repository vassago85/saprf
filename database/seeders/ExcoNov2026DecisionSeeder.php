<?php

namespace Database\Seeders;

use App\Enums\DecisionStatus;
use App\Models\Decision;
use App\Models\DecisionTally;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Drafts from the 10 Nov 2026 ExCo meeting that are cleared to publish.
 *
 * Left out on purpose:
 *   - Disciplinary matter (no outcome yet; do not publish names or allegations)
 *   - Sunday rimfire scheduling (folds into the 2027 calendar, not a register entry)
 *
 * Saved as drafts so ExCo can batch-publish and send one digest email.
 * Idempotent: matched on title. Drafts are refreshed; published rows are left alone.
 */
class ExcoNov2026DecisionSeeder extends Seeder
{
    private const REFERENCE = 'ExCo, 10 Nov 2026';

    /**
     * @var array<int, array{title: string, body: string}>
     */
    private const DECISIONS = [
        [
            'title' => '2027 National Team selection committee constituted',
            'body' => 'ExCo resolved to begin the 2027 national team selection process now rather than wait until the end of April. A selection committee has been constituted, with ExCo members and potential team members excluded from the selection pool to avoid conflicts of interest. The committee is constituted subject to all proposed members confirming acceptance, after which it will be formally ratified by ExCo.',
        ],
        [
            'title' => 'Federation colours replace provincial colours at federation level',
            'body' => 'ExCo resolved to introduce SAPRF Federation Colours in gold, silver and bronze, calculated per division against the top score in that division. The cut-off is 85 percent: 85 to 90 is bronze, 90 to 95 is silver, and above 95 is gold. To qualify, a shooter must shoot the one-day national that counts as the provincial in their province. Provinces do not currently issue their own colours, but may do so if they wish.',
        ],
        [
            'title' => 'Squadding at SA Champs by ranking and division',
            'body' => 'ExCo resolved that squadding at SA Championships will be set by overall ranking within division: 1 to 10 in Squad 1, 11 to 20 in Squad 2, 21 to 30 in Squad 3, and so on. Ladies and juniors will each have their own squad if there are enough entries to fill one. Other matches during the year will continue to allow shooters to select their own squad.',
        ],
        [
            'title' => 'National match minimum of 100 rounds per day for 2027 centerfire and rimfire',
            'body' => 'ExCo resolved that the minimum match format for 2027 centerfire and rimfire national matches is 100 rounds for the day. The exact stage breakdown per venue is still being worked through with match directors and will be communicated once confirmed.',
        ],
        [
            'title' => '2027 ruleset: IPRF with PRS for the African Championship',
            'body' => 'ExCo resolved that all 2027 SAPRF matches will be run under IPRF rules, with the exception of the PRS African Championship which will run under PRS rules as a PRS-sanctioned event. For the African Championship, the PRS factory division limits apply, including the scope and rifle price caps as listed on PRS. For all other SAPRF matches no monetary limit applies on scope or rifle. A summary of the rules will be posted on the SAPRF website.',
        ],
        [
            'title' => 'Gauteng chair succession and January 2027 AGMs',
            'body' => 'ExCo confirmed that the correct procedure where a chair steps down mid-term is for the vice-chair to act as interim chair. Clive Mey will act as interim Gauteng chair and will call a Gauteng AGM in January 2027 to elect a new chair and vice-chair. The SAPRF AGM will also be held in January 2027.',
        ],
        [
            'title' => 'Members must belong to an affiliated SAPRF club in their province',
            'body' => 'ExCo resolved that SAPRF members must belong to a SAPRF-affiliated club in their province. Where no affiliated club exists in a member’s province, direct SAPRF membership will be permitted. This tightens prior practice and will be communicated to all members and clubs.',
        ],
        [
            'title' => 'Monthly provincial or club match per province in 2027',
            'body' => 'ExCo resolved to aim for a provincial or club match in each province every month, or at minimum every second month, during 2027 as preparation for the 2028 world championships. Scheduling will avoid clashing with a national match in the same province in the same month.',
        ],
        [
            'title' => 'IPRF World Cup organising committee to be established',
            'body' => 'ExCo resolved to establish an IPRF World Cup organising committee. The host venue will be allocated a seat on the committee. All sponsorship and marketing for the event must be approved through this committee to comply with the IPRF memorandum of understanding. ExCo members will submit nominations, with preference for people who have attended previous IPRF World Cup events. Formal correspondence to the host venue will follow once the committee is in place.',
        ],
        [
            'title' => 'Match director rules refresher and consistent application',
            'body' => 'ExCo resolved to recirculate the existing match director guideline booklet to all MDs so that IPRF rules and SAPRF processes are applied consistently at every match, including DQ decisions. The booklet will be distributed by ExCo and discussion opened in the MD group.',
        ],
    ];

    public function run(): void
    {
        $author = User::role('exco')->orderBy('id')->first()
            ?? User::query()->orderBy('id')->first();

        if ($author === null) {
            throw new RuntimeException('No user exists to own the seeded decisions. Seed users first.');
        }

        $created = 0;
        $updated = 0;

        foreach (self::DECISIONS as $row) {
            $decision = Decision::query()->where('title', $row['title'])->first();

            if ($decision !== null) {
                if ($decision->isDraft()) {
                    $decision->update([
                        'body' => $row['body'],
                        'reference' => self::REFERENCE,
                    ]);
                    $updated++;
                }

                $this->ensureTally($decision);

                continue;
            }

            $decision = Decision::create([
                'title' => $row['title'],
                'body' => $row['body'],
                'reference' => self::REFERENCE,
                'status' => DecisionStatus::Draft,
                'published_at' => null,
                'created_by' => $author->id,
            ]);

            $this->ensureTally($decision);
            $created++;
        }

        $this->command?->info("10 Nov 2026 ExCo decisions: {$created} created, {$updated} drafts refreshed. Held as drafts for batch publish.");
    }

    private function ensureTally(Decision $decision): void
    {
        DecisionTally::query()->firstOrCreate(
            ['decision_id' => $decision->id],
            ['likes' => 0, 'dislikes' => 0, 'neutrals' => 0],
        );
    }
}
