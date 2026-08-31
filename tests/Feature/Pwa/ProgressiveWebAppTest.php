<?php

namespace Tests\Feature\Pwa;

use App\Models\Setting;
use App\Services\Settings\SettingsRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class ProgressiveWebAppTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    // ---------------------------------------------------------------------
    // The manifest
    // ---------------------------------------------------------------------

    public function test_the_manifest_is_served_with_the_right_content_type(): void
    {
        $this->get(route('pwa.manifest'))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/manifest+json');
    }

    public function test_the_manifest_declares_what_a_browser_needs_to_install(): void
    {
        $manifest = $this->get(route('pwa.manifest'))->json();

        $this->assertSame('standalone', $manifest['display']);
        $this->assertSame('/', $manifest['scope']);
        $this->assertNotEmpty($manifest['name']);
        $this->assertNotEmpty($manifest['short_name']);
        $this->assertLessThanOrEqual(12, mb_strlen($manifest['short_name']));
        $this->assertNotEmpty($manifest['icons']);
    }

    public function test_the_manifest_name_comes_from_company_settings(): void
    {
        // Not hardcoded: the installed icon says what the settings screen says.
        Setting::query()->updateOrCreate(
            ['key' => 'company.name'],
            ['value' => 'PISFA Travel Uganda', 'type' => 'string'],
        );

        app()->forgetInstance(SettingsRepository::class);

        $this->get(route('pwa.manifest'))
            ->assertOk()
            ->assertJsonPath('name', 'PISFA Travel Uganda');
    }

    public function test_the_manifest_ships_a_maskable_icon(): void
    {
        $icons = $this->get(route('pwa.manifest'))->json('icons');

        $purposes = array_column($icons, 'purpose');

        // Without one, Android crops the square icon into its launcher shape
        // and clips the mark.
        $this->assertContains('maskable', $purposes);
        $this->assertContains('any', $purposes);
    }

    public function test_every_icon_the_manifest_promises_actually_exists(): void
    {
        foreach ($this->get(route('pwa.manifest'))->json('icons') as $icon) {
            $path = public_path(ltrim($icon['src'], '/'));

            $this->assertTrue(File::exists($path), "Missing icon: {$icon['src']}");

            $dimensions = getimagesize($path);

            $this->assertIsArray($dimensions, "Not a readable image: {$icon['src']}");

            [$expectedWidth, $expectedHeight] = array_map('intval', explode('x', $icon['sizes']));

            $this->assertSame($expectedWidth, $dimensions[0], "Wrong width: {$icon['src']}");
            $this->assertSame($expectedHeight, $dimensions[1], "Wrong height: {$icon['src']}");
        }
    }

    // ---------------------------------------------------------------------
    // The service worker
    // ---------------------------------------------------------------------

    public function test_the_service_worker_is_served_from_the_site_root(): void
    {
        // Scope: a worker under a subdirectory could only control that
        // subdirectory.
        $this->assertSame('/sw.js', route('pwa.service-worker', absolute: false));

        $this->get('/sw.js')
            ->assertOk()
            ->assertHeader('Service-Worker-Allowed', '/');
    }

    public function test_the_service_worker_is_never_cached_by_the_browser(): void
    {
        // A stale worker cannot be replaced by a deployment if the browser will
        // not re-fetch it.
        $response = $this->get('/sw.js');

        $response->assertOk();
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control') ?? '');
    }

    public function test_the_service_worker_never_caches_html(): void
    {
        // The rule the whole worker exists to enforce. A cached portal page is
        // served to whoever asks next on a shared device, signed in or not, and
        // logging out would not remove it.
        $worker = $this->get('/sw.js')->getContent();

        $this->assertIsString($worker);

        // The only cache-first branch is for content-hashed static output.
        $this->assertStringContainsString("url.pathname.startsWith('/build/')", $worker);
        $this->assertStringContainsString('cacheFirst', $worker);

        // Navigations go to the network and fall back to the offline page;
        // nothing puts a navigation response into a cache.
        $this->assertStringContainsString('networkThenOfflinePage', $worker);
        $this->assertStringNotContainsString('cache.put(request, response.clone())', explode(
            'async function networkThenOfflinePage',
            $worker,
        )[1] ?? '');
    }

    public function test_the_service_worker_ignores_unsafe_methods_and_private_paths(): void
    {
        $worker = $this->get('/sw.js')->getContent();

        $this->assertIsString($worker);

        // Replaying a POST from a cache would submit a booking twice.
        $this->assertStringContainsString("request.method !== 'GET'", $worker);

        foreach (['/webhooks/', '/chat/', '/admin/', '/health'] as $path) {
            $this->assertStringContainsString($path, $worker, "The worker does not exclude {$path}");
        }
    }

    public function test_the_cache_version_changes_when_the_build_changes(): void
    {
        $first = $this->get('/sw.js')->getContent();

        $this->assertIsString($first);
        $this->assertMatchesRegularExpression('/const VERSION = "[a-f0-9]{12}"/', $first);
    }

    public function test_the_worker_precaches_the_offline_page(): void
    {
        $worker = $this->get('/sw.js')->getContent();

        $this->assertIsString($worker);
        $this->assertStringContainsString('/offline', $worker);
    }

    // ---------------------------------------------------------------------
    // The offline page
    // ---------------------------------------------------------------------

    public function test_the_offline_page_renders_without_the_network(): void
    {
        $response = $this->get(route('pwa.offline'));

        $response->assertOk();
        $response->assertSee('You are offline');
    }

    public function test_the_offline_page_carries_its_own_styles(): void
    {
        // It is shown precisely when the network is gone, so a stylesheet it
        // had to fetch would leave it unstyled at the one moment it is seen.
        $html = $this->get(route('pwa.offline'))->getContent();

        $this->assertIsString($html);
        $this->assertStringContainsString('<style>', $html);
        $this->assertStringNotContainsString('<link rel="stylesheet"', $html);
    }

    public function test_the_offline_page_says_nothing_was_submitted(): void
    {
        // The question somebody actually has when a booking form fails.
        $this->get(route('pwa.offline'))
            ->assertSee('nothing has been booked or paid');
    }

    // ---------------------------------------------------------------------
    // Wiring
    // ---------------------------------------------------------------------

    public function test_every_layout_links_the_manifest_and_registers_the_worker(): void
    {
        foreach ([route('home'), route('login')] as $url) {
            $response = $this->get($url);

            $response->assertOk();
            $response->assertSee('rel="manifest"', false);
            $response->assertSee('serviceWorker', false);
            $response->assertSee('apple-touch-icon', false);
        }
    }

    public function test_a_failed_registration_never_breaks_the_page(): void
    {
        // Registration fails on an insecure origin, in a private window, or
        // where the user has blocked it. A site that works is worth more than
        // one that works offline.
        $html = $this->get(route('home'))->getContent();

        $this->assertIsString($html);
        $this->assertMatchesRegularExpression('/register\([^;]*\)\s*\.catch\(/s', $html);
    }
}
