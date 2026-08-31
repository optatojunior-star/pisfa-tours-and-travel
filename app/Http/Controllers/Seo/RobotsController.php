<?php

namespace App\Http\Controllers\Seo;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;

/**
 * robots.txt, generated so it can point at the sitemap and stay in step with
 * the routes that actually exist.
 *
 * The disallow list is not a security measure — anything genuinely private is
 * behind authentication, and robots.txt is a public file that politely asks
 * crawlers to stay away. It exists to stop search engines wasting crawl budget
 * on pages that will only ever return a login redirect, and to keep customer
 * portal URLs out of search results where they help nobody.
 */
class RobotsController extends Controller
{
    public function __invoke(): Response
    {
        $lines = [
            'User-agent: *',
            '',
            '# Authenticated areas. Nothing here is reachable without a session;',
            '# listing them only saves crawlers a wasted request.',
            'Disallow: /admin/',
            'Disallow: /portal/',
            'Disallow: /dashboard',
            'Disallow: /profile',
            'Disallow: /login',
            'Disallow: /register',
            'Disallow: /forgot-password',
            'Disallow: /reset-password',
            'Disallow: /verify-email',
            'Disallow: /two-factor-challenge',
            '',
            '# Machine endpoints with nothing to index.',
            'Disallow: /webhooks/',
            'Disallow: /chat/',
            'Disallow: /health',
            'Disallow: /up',
            '',
            '# Filtered and paginated views are the same content reshuffled.',
            'Disallow: /*?q=',
            'Disallow: /*?page=',
            '',
        ];

        if (! app()->environment('production')) {
            // A staging copy indexed alongside the real site competes with it
            // for the same searches and splits the ranking.
            $lines = ['User-agent: *', 'Disallow: /', ''];
        }

        $lines[] = 'Sitemap: '.route('sitemap');
        $lines[] = '';

        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'public, max-age=86400',
        ]);
    }
}
