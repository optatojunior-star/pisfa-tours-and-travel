<?php

namespace App\Http\Controllers\Seo;

use App\Enums\ListingStatus;
use App\Enums\PostStatus;
use App\Enums\PropertyStatus;
use App\Enums\TourPackageStatus;
use App\Enums\VehicleCatalogueStatus;
use App\Http\Controllers\Controller;
use App\Models\Post;
use App\Models\Property;
use App\Models\TourPackage;
use App\Models\Vehicle;
use App\Models\VehicleListing;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

/**
 * The sitemap, generated from live published content.
 *
 * Built on request rather than written to a file, so it can never describe
 * yesterday's catalogue. It is small — a few hundred URLs at most — and cached
 * for an hour, which is far cheaper than the class of bug where a stale
 * sitemap.xml keeps advertising a tour that was withdrawn last month.
 *
 * Only publicly visible records are listed. A draft tour or a withdrawn listing
 * in a sitemap is an invitation for a crawler to index a 404.
 */
class SitemapController extends Controller
{
    public function __invoke(): Response
    {
        $urls = cache()->remember('sitemap.urls', now()->addHour(), fn (): array => $this->urls());

        $xml = view('seo.sitemap', ['urls' => $urls])->render();

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=utf-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * @return list<array{loc: string, lastmod: string|null, changefreq: string, priority: string}>
     */
    private function urls(): array
    {
        $urls = [];

        // Static pages, highest priority first.
        $static = [
            ['home', '1.0', 'weekly'],
            ['tours.index', '0.9', 'daily'],
            ['car-hire.index', '0.9', 'daily'],
            ['accommodation.index', '0.8', 'daily'],
            ['showroom.index', '0.8', 'daily'],
            ['airport-transfers.index', '0.8', 'weekly'],
            ['vehicle-imports.create', '0.7', 'monthly'],
            ['leasing.create', '0.7', 'monthly'],
            ['flight-inquiries.create', '0.7', 'monthly'],
            ['blog.index', '0.7', 'weekly'],
            ['about', '0.6', 'monthly'],
            ['contact', '0.6', 'monthly'],
            ['request-quotation', '0.6', 'monthly'],
            ['privacy', '0.3', 'yearly'],
            ['terms', '0.3', 'yearly'],
        ];

        foreach ($static as [$name, $priority, $frequency]) {
            if (Route::has($name)) {
                $urls[] = $this->url(route($name), null, $frequency, $priority);
            }
        }

        // Published tours.
        TourPackage::query()
            ->where('status', TourPackageStatus::Published->value)
            ->select(['slug', 'updated_at'])
            ->each(function (TourPackage $tour) use (&$urls): void {
                $urls[] = $this->url(route('tours.show', $tour->slug), $tour->updated_at, 'weekly', '0.8');
            });

        // Published hire vehicles.
        if (Route::has('car-hire.show')) {
            Vehicle::query()
                ->where('catalogue_status', VehicleCatalogueStatus::Published->value)
                ->select(['slug', 'updated_at'])
                ->each(function (Vehicle $vehicle) use (&$urls): void {
                    $urls[] = $this->url(route('car-hire.show', $vehicle->slug), $vehicle->updated_at, 'weekly', '0.7');
                });
        }

        // Published properties.
        if (Route::has('accommodation.show')) {
            Property::query()
                ->where('status', PropertyStatus::Published->value)
                ->select(['slug', 'updated_at'])
                ->each(function (Property $property) use (&$urls): void {
                    $urls[] = $this->url(route('accommodation.show', $property->slug), $property->updated_at, 'weekly', '0.7');
                });
        }

        // Showroom listings a visitor can actually see.
        VehicleListing::query()
            ->whereIn('status', ListingStatus::publicValues())
            ->select(['slug', 'updated_at'])
            ->each(function (VehicleListing $listing) use (&$urls): void {
                $urls[] = $this->url(route('showroom.show', $listing->slug), $listing->updated_at, 'weekly', '0.6');
            });

        // Published journal posts, excluding anything scheduled for the future.
        Post::query()
            ->where('status', PostStatus::Published->value)
            ->where('published_at', '<=', now())
            ->select(['slug', 'updated_at'])
            ->each(function (Post $post) use (&$urls): void {
                $urls[] = $this->url(route('blog.show', $post->slug), $post->updated_at, 'monthly', '0.6');
            });

        return $urls;
    }

    /**
     * @return array{loc: string, lastmod: string|null, changefreq: string, priority: string}
     */
    private function url(string $loc, mixed $lastmod, string $changefreq, string $priority): array
    {
        return [
            'loc' => $loc,
            'lastmod' => $lastmod instanceof \DateTimeInterface ? $lastmod->format('Y-m-d') : null,
            'changefreq' => $changefreq,
            'priority' => $priority,
        ];
    }
}
