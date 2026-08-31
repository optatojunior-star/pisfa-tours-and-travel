<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Post;
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
        ]);
    }

    public function about(): View
    {
        return view('marketing.about');
    }

    public function contact(): View
    {
        return view('marketing.contact', ['services' => self::SERVICES]);
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
