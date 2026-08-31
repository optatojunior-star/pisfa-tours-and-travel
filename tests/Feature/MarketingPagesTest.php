<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\NewsletterSubscriber;
use App\Support\ServiceCatalogue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MarketingPagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_public_marketing_pages_are_available_through_named_routes(): void
    {
        $pages = [
            'home' => 'Journeys planned around people, not packages.',
            'about' => 'Travel support built around trust',
            'contact' => 'Start with the details that matter to you.',
            'request-quotation' => 'Tell us what a successful journey looks like.',
            'privacy' => 'Privacy policy',
            'terms' => 'Terms and conditions',
        ];

        foreach ($pages as $routeName => $expectedText) {
            $this->get(route($routeName))
                ->assertOk()
                ->assertSee($expectedText);
        }
    }

    public function test_every_service_route_in_the_catalogue_actually_resolves(): void
    {
        // A card badged "Available online" that leads nowhere is worse than one
        // that admits the service is arranged by hand.
        foreach (ServiceCatalogue::SERVICES as $slug => $service) {
            if (! isset($service['route'])) {
                continue;
            }

            $this->assertTrue(
                app('router')->has($service['route']),
                "The {$slug} card names a route that does not exist: {$service['route']}",
            );

            $this->get(route($service['route']))
                ->assertOk();
        }
    }

    public function test_a_service_with_a_public_page_is_never_advertised_as_unavailable(): void
    {
        // The failure this guards against: a module ships, its public page goes
        // live, and the homepage carries on telling visitors it is not there
        // because nobody added the route to ServiceCatalogue. Every one of
        // these was in exactly that state until it was found by running the app.
        $shipped = [
            'tours-safaris' => 'tours.index',
            'car-hire' => 'car-hire.index',
            'airport-transfers' => 'airport-transfers.index',
            'vehicle-imports' => 'vehicle-imports.create',
            'vehicle-sales' => 'showroom.index',
            'accommodation' => 'accommodation.index',
            'vehicle-leasing' => 'leasing.create',
        ];

        foreach ($shipped as $slug => $route) {
            $this->assertSame(
                $route,
                ServiceCatalogue::SERVICES[$slug]['route'] ?? null,
                "The {$slug} service has a live public page but the catalogue does not link to it.",
            );
        }
    }

    public function test_service_cards_are_truthfully_labelled_and_link_to_a_working_request_page(): void
    {
        $this->get(route('home'))
            ->assertOk()
            ->assertSee('Arranged with our team')
            ->assertSee(route('tours.index'), false)
            ->assertSee(route('car-hire.index'), false)
            ->assertDontSee('/tours/book');

        // The page now files a real quotation request rather than a contact
        // message, so it must post to the billing endpoint and still promise
        // that asking for a price commits the visitor to nothing.
        $this->get(route('request-quotation', ['service' => 'tours-safaris']))
            ->assertOk()
            ->assertSee('value="tours-safaris" selected', false)
            ->assertSee(route('quotation-requests.store'), false)
            ->assertSee('Nothing is charged until you accept.');
    }

    public function test_contact_submission_is_normalized_and_persisted(): void
    {
        $response = $this->post(route('contact.store'), [
            'name' => '  Amina Nakitende  ',
            'email' => '  AMINA@EXAMPLE.COM ',
            'phone' => ' +256 700 123 456 ',
            'service' => 'tours-safaris',
            'source' => 'quotation',
            'message' => '  We are planning a six-person safari in western Uganda next March.  ',
            'status' => 'resolved',
        ]);

        $response
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionHas('contact_success');

        $this->assertDatabaseHas('contact_messages', [
            'name' => 'Amina Nakitende',
            'email' => 'amina@example.com',
            'phone' => '+256 700 123 456',
            'service' => 'tours-safaris',
            'source' => 'quotation',
            'message' => 'We are planning a six-person safari in western Uganda next March.',
            'status' => 'new',
        ]);
    }

    public function test_contact_submission_uses_its_own_validation_error_bag(): void
    {
        $this->from(route('contact'))
            ->post(route('contact.store'), [
                'name' => 'A',
                'email' => 'not-an-email',
                'phone' => 'not a telephone',
                'service' => 'made-up-service',
                'source' => 'contact',
                'message' => 'Too short',
            ])
            ->assertRedirect(route('contact'))
            ->assertSessionHasErrors(
                ['name', 'email', 'phone', 'service', 'message'],
                null,
                'contact'
            );

        $this->assertDatabaseCount('contact_messages', 0);
    }

    public function test_contact_submission_is_rate_limited(): void
    {
        $payload = [
            'name' => 'Rate Limit Test',
            'email' => 'rate-limit@example.com',
            'phone' => '+256700123456',
            'service' => 'car-hire',
            'source' => 'contact',
            'message' => 'This valid message exists to verify contact form throttling behavior.',
        ];

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.55'])
                ->post(route('contact.store'), $payload)
                ->assertRedirect();
        }

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.55'])
            ->post(route('contact.store'), $payload)
            ->assertStatus(429);

        $this->assertDatabaseCount('contact_messages', 5);
    }

    public function test_newsletter_subscription_is_persisted_and_duplicate_email_is_idempotent(): void
    {
        $first = $this->from(route('home'))->post(route('newsletter.store'), [
            'email' => ' TRAVELER@EXAMPLE.COM ',
            'source' => 'footer',
        ]);

        $first
            ->assertRedirect(route('home'))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('newsletter_success');

        $this->assertDatabaseHas('newsletter_subscribers', [
            'email' => 'traveler@example.com',
            'status' => 'active',
            'source' => 'footer',
        ]);

        $this->from(route('about'))->post(route('newsletter.store'), [
            'email' => 'traveler@example.com',
            'source' => 'footer',
        ])->assertRedirect(route('about'));

        $this->assertDatabaseCount('newsletter_subscribers', 1);
    }

    public function test_newsletter_requires_a_valid_email_and_known_source(): void
    {
        $this->from(route('home'))
            ->post(route('newsletter.store'), [
                'email' => 'wrong',
                'source' => 'untrusted-source',
            ])
            ->assertRedirect(route('home'))
            ->assertSessionHasErrors(['email', 'source'], null, 'newsletter');

        $this->assertDatabaseCount('newsletter_subscribers', 0);
    }

    public function test_marketing_models_do_not_accept_unvalidated_status_overrides_from_controllers(): void
    {
        $this->post(route('newsletter.store'), [
            'email' => 'safe@example.com',
            'source' => 'website',
            'status' => 'blocked',
        ])->assertRedirect();

        $subscriber = NewsletterSubscriber::query()->sole();

        $this->assertSame('active', $subscriber->status);
        $this->assertNotNull($subscriber->subscribed_at);
        $this->assertSame(0, ContactMessage::query()->count());
    }
}
