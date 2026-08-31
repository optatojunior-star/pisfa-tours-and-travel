<?php

namespace Tests\Feature\Seo;

use App\Enums\PostStatus;
use App\Enums\TourPackageStatus;
use App\Models\Post;
use App\Models\TourPackage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    // ---------------------------------------------------------------------
    // Sitemap
    // ---------------------------------------------------------------------

    public function test_the_sitemap_is_valid_xml(): void
    {
        $response = $this->get(route('sitemap'));

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/xml; charset=utf-8');

        $xml = simplexml_load_string((string) $response->getContent());

        $this->assertNotFalse($xml, 'The sitemap is not parseable XML.');
        $this->assertGreaterThan(0, count($xml->url));
    }

    public function test_the_sitemap_lists_published_content(): void
    {
        $tour = TourPackage::factory()->create(['status' => TourPackageStatus::Published]);

        cache()->forget('sitemap.urls');

        $this->get(route('sitemap'))
            ->assertOk()
            ->assertSee(route('tours.show', $tour->slug), false);
    }

    public function test_the_sitemap_never_lists_unpublished_content(): void
    {
        // A draft in a sitemap invites a crawler to index a 404.
        $draft = TourPackage::factory()->create(['status' => TourPackageStatus::Draft]);
        $scheduled = Post::factory()->create([
            'status' => PostStatus::Published,
            'published_at' => now()->addWeek(),
        ]);

        cache()->forget('sitemap.urls');

        $response = $this->get(route('sitemap'));

        $response->assertOk();
        $response->assertDontSee(route('tours.show', $draft->slug), false);
        $response->assertDontSee(route('blog.show', $scheduled->slug), false);
    }

    // ---------------------------------------------------------------------
    // robots.txt
    // ---------------------------------------------------------------------

    public function test_robots_points_at_the_sitemap(): void
    {
        $this->get(route('robots'))
            ->assertOk()
            ->assertSee('Sitemap: '.route('sitemap'), false);
    }

    public function test_robots_keeps_crawlers_out_of_authenticated_areas(): void
    {
        // Only production serves the full list; everywhere else correctly asks
        // not to be indexed at all, which is what the next test covers.
        $this->app->detectEnvironment(fn (): string => 'production');

        $response = $this->get(route('robots'));

        foreach (['/admin/', '/portal/', '/dashboard', '/webhooks/'] as $path) {
            $response->assertSee('Disallow: '.$path, false);
        }
    }

    public function test_a_non_production_deployment_asks_not_to_be_indexed(): void
    {
        // A staging copy indexed beside the real site competes with it for the
        // same searches.
        $this->app->detectEnvironment(fn (): string => 'staging');

        $this->get(route('robots'))
            ->assertOk()
            ->assertSee("User-agent: *\nDisallow: /", false);
    }

    // ---------------------------------------------------------------------
    // Meta tags
    // ---------------------------------------------------------------------

    public function test_every_public_page_carries_the_tags_a_share_needs(): void
    {
        foreach (['home', 'about', 'contact', 'tours.index', 'blog.index'] as $route) {
            $response = $this->get(route($route));

            $response->assertOk();

            foreach (['og:title', 'og:description', 'og:image', 'og:url', 'og:type',
                'twitter:card', 'twitter:title'] as $tag) {
                $response->assertSee($tag, false);
            }

            $response->assertSee('rel="canonical"', false);
        }
    }

    public function test_the_description_is_trimmed_to_what_a_result_shows(): void
    {
        $html = (string) $this->get(route('home'))->getContent();

        preg_match('/<meta name="description" content="([^"]*)"/', $html, $m);

        $this->assertNotEmpty($m, 'No meta description was rendered.');
        $this->assertLessThanOrEqual(160, strlen($m[1]),
            'A description longer than about 155 characters is cut off mid-sentence in results.');
    }

    public function test_the_structured_data_is_valid_json(): void
    {
        $html = (string) $this->get(route('home'))->getContent();

        preg_match_all('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        $this->assertNotEmpty($m[1], 'No JSON-LD was rendered.');

        foreach ($m[1] as $block) {
            $decoded = json_decode($block, true);

            $this->assertIsArray($decoded, 'A JSON-LD block is not valid JSON: '.json_last_error_msg());
            $this->assertArrayHasKey('@context', $decoded);
            $this->assertArrayHasKey('@type', $decoded);
        }
    }

    public function test_the_organisation_schema_describes_the_business(): void
    {
        $html = (string) $this->get(route('home'))->getContent();

        preg_match('#<script type="application/ld\+json">(.*?)</script>#s', $html, $m);

        $schema = json_decode($m[1], true);

        $this->assertSame('TravelAgency', $schema['@type']);
        $this->assertNotEmpty($schema['name']);
        $this->assertNotEmpty($schema['url']);
        $this->assertSame('Uganda', $schema['areaServed']['name']);
    }
}
