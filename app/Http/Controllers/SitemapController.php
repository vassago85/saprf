<?php

namespace App\Http\Controllers;

use App\Models\MatchEvent;
use Illuminate\Http\Response;

/**
 * Public sitemap.xml for Search Console and crawlers.
 *
 * Only indexable guest URLs are listed. Authenticated app routes
 * (/dashboard, /matches, /registrations, …) stay out on purpose.
 */
class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $urls = [
            $this->url(route('home'), 'daily', '1.0'),
            $this->url(route('llms'), 'weekly', '0.5'),
            $this->url(route('llms.full'), 'weekly', '0.4'),
            $this->url(route('events.index'), 'daily', '0.9'),
            $this->url(route('standings.public'), 'daily', '0.9'),
            $this->url(route('faq.index'), 'weekly', '0.7'),
            $this->url(route('contact.create'), 'monthly', '0.6'),
            $this->url(route('documents.index'), 'weekly', '0.7'),
            $this->url(route('documents.search'), 'weekly', '0.4'),
            $this->url(route('rules.regulations'), 'monthly', '0.7'),
            $this->url(route('rules.divisions'), 'monthly', '0.6'),
            $this->url(route('rules.pr22-rimfire'), 'monthly', '0.6'),
            $this->url(route('legal.constitution'), 'yearly', '0.5'),
            $this->url(route('legal.terms'), 'yearly', '0.4'),
            $this->url(route('legal.privacy'), 'yearly', '0.4'),
            $this->url(route('legal.code-of-conduct'), 'yearly', '0.4'),
            $this->url(route('legal.conflict-of-interest'), 'yearly', '0.4'),
            $this->url(route('selection.policy.public', ['series' => 'prs']), 'monthly', '0.6'),
            $this->url(route('selection.policy.public', ['series' => 'pr22']), 'monthly', '0.6'),
        ];

        $matches = MatchEvent::query()
            ->published()
            ->orderByDesc('match_date')
            ->get(['id', 'updated_at']);

        foreach ($matches as $match) {
            $urls[] = $this->url(
                route('events.show', $match),
                'weekly',
                '0.7',
                $match->updated_at?->toAtomString(),
            );
        }

        return response()
            ->view('sitemap', ['urls' => $urls])
            ->header('Content-Type', 'application/xml');
    }

    /**
     * @return array{loc: string, changefreq: string, priority: string, lastmod: ?string}
     */
    private function url(string $loc, string $changefreq, string $priority, ?string $lastmod = null): array
    {
        return [
            'loc' => $loc,
            'changefreq' => $changefreq,
            'priority' => $priority,
            'lastmod' => $lastmod,
        ];
    }
}
