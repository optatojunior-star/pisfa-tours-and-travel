<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\MediaAlbum;
use App\Models\Post;
use App\Models\ServiceImage;
use App\Models\TeamMember;
use App\Support\ServiceCatalogue;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class PublicPageController extends Controller
{
    /**
     * The catalogue lives in one place so the marketing pages, the
     * quotation-request form, and the admin console cannot drift apart.
     *
     * @var array<string, array<string, string>>
     */
    public const SERVICES = ServiceCatalogue::SERVICES;

    public function home(): View
    {
        return view('marketing.home', [
            'services' => self::SERVICES,
            // Uploaded pictures replace the line icons where one exists.
            // A service with none keeps its icon rather than showing a gap.
            'serviceImages' => ServiceImage::urlsByServiceKey(),
            // Featured, published, and dated in the past — the same public
            // scope the journal itself uses, so the home page cannot surface
            // a post the blog would hide.
            'featuredPosts' => Post::query()
                ->published()
                ->featured()
                ->with(['category:id,name,slug', 'media'])
                ->latest('published_at')
                ->limit(3)
                ->get(),
            // The sliding gallery. Read from the reserved album rather than
            // created here: homeGallery() would write a row on every home-page
            // request, and the public site must never create records.
            'galleryImages' => MediaAlbum::query()
                ->where('slug', 'home-gallery')
                ->first()
                ?->galleryImages()
                ->limit(24)
                ->get() ?? collect(),
        ]);
    }

    public function about(): View
    {
        return view('marketing.about', [
            // Only published profiles, in the order somebody chose by hand.
            // An empty list is a normal state, and the page omits the whole
            // section rather than showing an empty heading.
            'team' => TeamMember::query()->published()->with('photographs')->get(),
        ]);
    }

    public function bookingTerms(): View
    {
        return view('marketing.booking-terms');
    }

    public function cancellationPolicy(): View
    {
        return view('marketing.cancellation-policy');
    }

    public function contact(): View
    {
        return view('marketing.contact', [
            'services' => self::SERVICES,
            'serviceImages' => ServiceImage::urlsByServiceKey(),
        ]);
    }

    public function requestQuotation(Request $request): View
    {
        $requestedService = $request->string('service')->toString();
        $selectedService = array_key_exists($requestedService, self::SERVICES)
            ? $requestedService
            : null;

        return view('marketing.request-quotation', [
            'services' => self::SERVICES,
            'selectedService' => $selectedService,
            // Minted per render and carried through `old()`, so a resubmit after
            // a validation error is deduplicated rather than filed twice.
            'idempotencyKey' => (string) Str::uuid(),
        ]);
    }

    public function privacy(): View
    {
        return view('marketing.privacy');
    }

    public function terms(): View
    {
        return view('marketing.terms');
    }
}
