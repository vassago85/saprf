<?php

namespace App\Http\Controllers;

use App\Models\MatchEvent;
use Illuminate\Http\Response;

/**
 * /llms.txt and /llms-full.txt — the llmstxt.org files language-model
 * crawlers look for. Public facts and URLs only; no member PII.
 */
class LlmsTxtController extends Controller
{
    public function index(): Response
    {
        return $this->plain($this->indexBody());
    }

    public function full(): Response
    {
        return $this->plain($this->fullBody());
    }

    private function plain(string $body): Response
    {
        return response($body, 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function indexBody(): string
    {
        $lines = [
            '# South African Precision Rifle Federation (SAPRF)',
            '',
            '> Official national governing body for Precision Rifle in South Africa. The platform covers PRS (centrefire) and PR22 (rimfire): match registration, scores, national and provincial standings, selection policy, and federation governance.',
            '',
            'Public HTML pages are canonical. This file is a curated map for language-model crawlers. A fuller dump (FAQ plus current published matches) is at [llms-full.txt]('.url('/llms-full.txt').'). XML sitemap: '.url('/sitemap.xml').'.',
            '',
            'AI crawlers are welcome on public pages. Do not use member dashboards, logins, payments, or admin URLs as sources.',
            '',
            '## Events & standings',
            '',
            '- [Events]('.route('events.index').'): Upcoming SAPRF matches and past results for PRS and PR22.',
            '- [Standings]('.route('standings.public').'): Official national and provincial rankings by season, series, and division.',
            '',
        ];

        $matches = $this->publishedUpcomingMatches();

        if ($matches->isNotEmpty()) {
            $lines[] = '## Upcoming published matches';
            $lines[] = '';

            foreach ($matches as $match) {
                $meta = collect([
                    $match->match_date?->format('Y-m-d'),
                    $match->match_type,
                    $match->series_level ? ucfirst(str_replace('_', ' ', $match->series_level)) : null,
                    $match->province?->name,
                ])->filter()->implode(' · ');

                $lines[] = '- ['.$match->name.']('.route('events.show', $match).'): '.$meta;
            }

            $lines[] = '';
        }

        $lines = array_merge($lines, [
            '## Rules & documents',
            '',
            '- [Documents index]('.route('documents.index').'): Directory of every public SAPRF publication.',
            '- [FAQ]('.route('faq.index').'): Membership, match entry, divisions, clubs, and SAPRF numbers.',
            '- [Rules & Regulations]('.route('rules.regulations').'): Course of fire, equipment, scoring, penalties.',
            '- [Divisions]('.route('rules.divisions').'): Open, Limited, Factory, Classic, and age/gender/service subdivisions.',
            '- [PR22 Rimfire Series Rules]('.route('rules.pr22-rimfire').'): Rimfire overlay, series structure, log weighting.',
            '- [PR22 team selection]('.route('selection.policy.public', ['series' => 'pr22']).'): Current IPRF rimfire selection cycle.',
            '- [PRS team selection (2026)]('.route('selection.policy.public', ['series' => 'prs', 'season' => '2026']).'): Historical centrefire cycle.',
            '',
            '## Legal & governance',
            '',
            '- [Constitution]('.route('legal.constitution').')',
            '- [Terms & Conditions]('.route('legal.terms').')',
            '- [Privacy Policy]('.route('legal.privacy').') (POPIA)',
            '- [Code of Conduct]('.route('legal.code-of-conduct').')',
            '- [Conflict of Interest Policy]('.route('legal.conflict-of-interest').')',
            '',
            '## Contact & membership',
            '',
            '- [Contact]('.route('contact.create').'): Public enquiry form.',
            '- [Join / register]('.route('register').'): Free SAPRF number; full membership is optional for series standings.',
            '',
            '## Optional',
            '',
            '- [llms-full.txt]('.url('/llms-full.txt').'): FAQ answers and the current published match list in one file.',
            '- Public JSON: '.url('/api/v1/matches/upcoming').' and '.url('/api/v1/standings').'.',
        ]);

        return implode("\n", $lines)."\n";
    }

    private function fullBody(): string
    {
        $faqPath = base_path('docs/faq.md');
        $faq = is_file($faqPath) ? trim((string) file_get_contents($faqPath)) : 'FAQ source is unavailable.';

        $matchLines = ['## Current published upcoming matches', ''];
        $matches = $this->publishedUpcomingMatches();

        if ($matches->isEmpty()) {
            $matchLines[] = 'No published upcoming matches are listed right now. See '.route('events.index').'.';
        } else {
            foreach ($matches as $match) {
                $matchLines[] = '- '.$match->name
                    .' — '.$match->match_date?->format('j F Y')
                    .' — '.$match->match_type
                    .' — '.route('events.show', $match);
            }
        }

        return $this->indexBody()
            ."\n---\n\n"
            .implode("\n", $matchLines)."\n\n---\n\n"
            .$faq
            ."\n";
    }

    /**
     * @return \Illuminate\Support\Collection<int, MatchEvent>
     */
    private function publishedUpcomingMatches()
    {
        return MatchEvent::query()
            ->published()
            ->upcoming()
            ->with('province:id,name')
            ->orderBy('match_date')
            ->limit(25)
            ->get(['id', 'name', 'match_type', 'series_level', 'match_date', 'province_id']);
    }
}
