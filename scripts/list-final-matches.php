<?php

/*
 * Dumps every match tagged as `series_level = 'final'` (the "Final / Champs"
 * dropdown option on the match edit form), broken down by series and season.
 * Shows which ones are flagged as the real SA Championships, the venue, the
 * date range, and how many valid scores landed on each one.
 *
 * Run from inside the app container:
 *     docker exec saprf-app php scripts/list-final-matches.php
 *
 * Optional filter by series/season:
 *     docker exec saprf-app php scripts/list-final-matches.php PRS 2026
 */

use App\Models\MatchEvent;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

$series = $argv[1] ?? null;   // PRS / PR22, or omit for both
$season = $argv[2] ?? null;   // e.g. 2026, or omit for every season on record

$query = MatchEvent::query()
    ->where('series_level', 'final')
    ->when($series, fn ($q) => $q->where('series', $series))
    ->when($season, fn ($q) => $q->where('season', $season))
    ->with('province:id,name,abbreviation')
    ->orderBy('series')
    ->orderBy('season')
    ->orderBy('match_date');

$matches = $query->get([
    'id', 'name', 'series', 'season', 'series_level',
    'is_sa_championships', 'province_id',
    'match_date', 'match_end_date', 'status', 'published',
    'source_national_match_id',
]);

if ($matches->isEmpty()) {
    echo "No matches tagged `series_level = final` found";
    echo $series || $season ? " for filter series={$series} season={$season}.\n" : ".\n";
    exit(0);
}

$label = function (MatchEvent $m): string {
    $tag = $m->is_sa_championships ? '[SA CHAMPS]' : '[final, NOT SA champs]';

    return str_pad($tag, 26);
};

$shown = 0;
foreach ($matches as $m) {
    $shown++;
    $province = $m->province?->name ?? '—';
    $start = $m->match_date?->format('Y-m-d') ?? '—';
    $end = $m->match_end_date?->format('Y-m-d') ?? $start;
    $validScores = $m->scores()->where('status', 'valid')->count();
    $distinctShooters = $m->scores()->where('status', 'valid')->distinct('user_id')->count('user_id');
    $divisions = $m->scores()->where('status', 'valid')->distinct('division_id')->count('division_id');

    // Day-1 sibling of this final, if any. Federation colours use this
    // sibling for live scores while the match is still running.
    $sibling = MatchEvent::query()
        ->where('source_national_match_id', $m->id)
        ->first(['id', 'name', 'match_date', 'status']);

    $sibScores = $sibling
        ? $sibling->scores()->where('status', 'valid')->count()
        : null;

    echo str_repeat('-', 100)."\n";
    echo sprintf(
        "ID %d  %s  %s %s\n",
        $m->id,
        $label($m),
        $m->series,
        $m->season,
    );
    echo "  Name:     {$m->name}\n";
    echo "  Venue:    {$province}\n";
    echo "  Dates:    {$start} → {$end}\n";
    echo "  Status:   {$m->status} / published=".($m->published ? 'yes' : 'no')."\n";
    echo "  Scores:   {$validScores} valid, across {$distinctShooters} shooters, {$divisions} divisions\n";

    if ($sibling) {
        $sibDate = $sibling->match_date?->format('Y-m-d') ?? '—';
        echo "  Day-1 sibling: ID {$sibling->id} ({$sibling->name}) on {$sibDate} — {$sibScores} valid scores\n";
    }
}

echo str_repeat('=', 100)."\n";
echo "Total: {$shown} match(es) tagged as `final`.\n";

$champs = $matches->where('is_sa_championships', true)->count();
$notChamps = $matches->where('is_sa_championships', false)->count();
echo "  → {$champs} flagged as SA Championships (federation colours award off these)\n";
echo "  → {$notChamps} tagged `final` but NOT flagged SA Champs (these DO NOT award colours)\n";

if ($notChamps > 0) {
    echo "\nIf any of the NOT-SA-champs rows above actually IS the real SA Championships,\n";
    echo "open it in the admin and tick 'This is the SA Championships', then save.\n";
    echo "If one of them should never have been a `final` at all (club champs, provincial champs, etc.),\n";
    echo "open it in the admin and change Series Level to the right value (usually `national` or `club`).\n";
}
