<?php

namespace App\Http\Controllers\Pwa;

use App\Http\Controllers\Controller;
use App\Services\Settings\SettingsRepository;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\File;
use Illuminate\View\View;

/**
 * The installable app: manifest, service worker, and offline page.
 *
 * Both the manifest and the worker are generated rather than shipped as static
 * files, for two reasons. The manifest takes its name from company settings, so
 * the installed icon says what the settings screen says rather than something
 * hardcoded in a file nobody remembers to edit. And the worker embeds a cache
 * version derived from the built assets, so a deployment invalidates old caches
 * by itself — a stale service worker serving last release's JavaScript is the
 * single most common way a PWA breaks after a deploy.
 *
 * The cost is one PHP request per worker fetch. Browsers check the worker at
 * most once a day plus on navigation, so it is not a hot path.
 */
class ProgressiveWebAppController extends Controller
{
    public function manifest(SettingsRepository $settings): JsonResponse
    {
        $brand = $settings->brand();
        $name = $brand['name'];

        return response()->json([
            'name' => $name,
            // Twelve characters is roughly what a phone home screen shows
            // before it truncates, so the short name is chosen, not derived.
            'short_name' => 'PISFA',
            'description' => $brand['tagline'] !== ''
                ? $brand['tagline']
                : 'Tours, car hire, airport transfers and travel services in Uganda.',
            'start_url' => '/?source=pwa',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'portrait-primary',
            'background_color' => '#f5f5f4',
            'theme_color' => '#064e3b',
            'lang' => 'en',
            'dir' => 'ltr',
            'categories' => ['travel', 'business'],
            'icons' => $this->icons(),
            // Deep links onto the things somebody installs the app to do.
            'shortcuts' => [
                [
                    'name' => 'Book a tour',
                    'short_name' => 'Tours',
                    'url' => '/tours?source=pwa',
                ],
                [
                    'name' => 'Hire a car',
                    'short_name' => 'Car hire',
                    'url' => '/car-hire?source=pwa',
                ],
                [
                    'name' => 'My bookings',
                    'short_name' => 'Bookings',
                    'url' => '/portal?source=pwa',
                ],
            ],
        ], 200, [
            'Content-Type' => 'application/manifest+json',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * The service worker.
     *
     * Served from the site root so its scope covers the whole application; a
     * worker under /build could only control /build.
     */
    public function serviceWorker(): Response
    {
        return response()
            ->view('pwa.service-worker', [
                'version' => $this->cacheVersion(),
                'offlineUrl' => route('pwa.offline', absolute: false),
                'precache' => $this->precache(),
            ])
            ->header('Content-Type', 'application/javascript; charset=utf-8')
            // Never cached by the browser's HTTP cache: a stale worker cannot
            // be replaced by a deployment if the browser will not re-fetch it.
            ->header('Cache-Control', 'no-cache, no-store, must-revalidate')
            ->header('Service-Worker-Allowed', '/');
    }

    public function offline(): View
    {
        return view('pwa.offline');
    }

    /**
     * A version string that changes whenever the built assets change.
     *
     * Read from the Vite manifest rather than from a constant somebody has to
     * remember to bump. Falls back to the application version so a deployment
     * without a build still produces a stable, distinct value.
     */
    private function cacheVersion(): string
    {
        $manifest = public_path('build/manifest.json');

        if (File::exists($manifest)) {
            return substr(hash_file('sha256', $manifest) ?: 'dev', 0, 12);
        }

        return substr(hash('sha256', (string) config('app.version', 'dev')), 0, 12);
    }

    /**
     * What the worker caches on install.
     *
     * Deliberately tiny: the offline page and the built CSS and JS. Everything
     * else is fetched from the network — see the worker itself for why HTML is
     * never cached.
     *
     * @return list<string>
     */
    private function precache(): array
    {
        $urls = [route('pwa.offline', absolute: false)];

        $manifest = public_path('build/manifest.json');

        if (! File::exists($manifest)) {
            return $urls;
        }

        $entries = json_decode(File::get($manifest), true);

        if (! is_array($entries)) {
            return $urls;
        }

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            if (isset($entry['file']) && is_string($entry['file'])) {
                $urls[] = '/build/'.$entry['file'];
            }

            foreach ($entry['css'] ?? [] as $css) {
                if (is_string($css)) {
                    $urls[] = '/build/'.$css;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    /**
     * @return list<array<string, string>>
     */
    private function icons(): array
    {
        $icons = [];

        foreach ([192, 512] as $size) {
            $icons[] = [
                'src' => "/icons/icon-{$size}.png",
                'sizes' => "{$size}x{$size}",
                'type' => 'image/png',
                'purpose' => 'any',
            ];
        }

        // A maskable icon has its own safe zone, so Android can crop it to
        // whatever shape the launcher uses without clipping the mark.
        $icons[] = [
            'src' => '/icons/icon-maskable-512.png',
            'sizes' => '512x512',
            'type' => 'image/png',
            'purpose' => 'maskable',
        ];

        return $icons;
    }
}
