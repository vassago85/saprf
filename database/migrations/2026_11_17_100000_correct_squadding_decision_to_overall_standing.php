<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The published squadding resolution stays as members voted on it.
 * This only writes the correction that sits under the vote.
 */
return new class extends Migration
{
    private const VOTED_TITLE = 'Squadding at SA Champs by ranking and division';

    private const VOTED_BODY = 'ExCo resolved that squadding at SA Championships will be set by overall ranking within division: 1 to 10 in Squad 1, 11 to 20 in Squad 2, 21 to 30 in Squad 3, and so on. Ladies and juniors will each have their own squad if there are enough entries to fill one. Other matches during the year will continue to allow shooters to select their own squad.';

    private const CORRECTION = 'The message sent by the chair is correct.';

    private const PREVIOUS_NOTE = 'As set out in the notice for SA Champs 21-22 November, squadding is by overall ranking. Ladies and juniors will each have their own squad if there are enough entries to fill one, or they will be squadded together in one squad. The first day of the SA Champs will also count as the last provincial match for the season.';

    public function up(): void
    {
        DB::table('decisions')
            ->where('title', self::VOTED_TITLE)
            ->update(['note' => self::CORRECTION]);

        // Undo a rewrite of the voted title and body, if one was applied.
        DB::table('decisions')
            ->where('title', 'Squadding at SA Champs by overall standing')
            ->update([
                'title' => self::VOTED_TITLE,
                'body' => self::VOTED_BODY,
                'note' => self::CORRECTION,
            ]);
    }

    public function down(): void
    {
        DB::table('decisions')
            ->where('title', self::VOTED_TITLE)
            ->where('note', self::CORRECTION)
            ->update(['note' => self::PREVIOUS_NOTE]);
    }
};
