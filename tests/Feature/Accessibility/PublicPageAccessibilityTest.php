<?php

namespace Tests\Feature\Accessibility;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\AccessibilityAudit;
use Tests\TestCase;

/**
 * Accessibility regressions on every public page and the signed-in shell.
 *
 * These checks prove what markup alone can prove. Colour contrast and focus
 * visibility need a browser and are covered in `tests/Browser`; whether alt
 * text is *useful* needs a person. A green run here means no page has lost its
 * headings, labels, or landmarks — not that the site is accessible.
 */
class PublicPageAccessibilityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    /**
     * Keyed so a failure names the page rather than a data-set index.
     *
     * @return array<string, array{0: string}>
     */
    public static function publicRoutes(): array
    {
        return [
            'home' => ['home'],
            'about' => ['about'],
            'contact' => ['contact'],
            'request a quotation' => ['request-quotation'],
            'privacy' => ['privacy'],
            'terms' => ['terms'],
            'tours' => ['tours.index'],
            'car hire' => ['car-hire.index'],
            'airport transfers' => ['airport-transfers.index'],
            'showroom' => ['showroom.index'],
            'accommodation' => ['accommodation.index'],
            'blog' => ['blog.index'],
            'login' => ['login'],
            'register' => ['register'],
            'offline' => ['pwa.offline'],
        ];
    }

    #[DataProvider('publicRoutes')]
    public function test_a_public_page_has_no_markup_accessibility_failures(string $route): void
    {
        if (! app('router')->has($route)) {
            $this->markTestSkipped("The route {$route} does not exist.");
        }

        $response = $this->get(route($route));

        $response->assertOk();

        $audit = new AccessibilityAudit($response->getContent() ?: '');

        $this->assertSame([], $audit->problems(), "Accessibility problems on the {$route} page.");
    }

    public function test_the_customer_portal_has_no_markup_accessibility_failures(): void
    {
        $customer = User::factory()->create([
            'role' => UserRole::Customer,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($customer)->get(route('portal.index'));

        $response->assertOk();

        $audit = new AccessibilityAudit($response->getContent() ?: '');

        $this->assertSame([], $audit->problems(), 'Accessibility problems in the customer portal.');
    }

    public function test_every_public_page_offers_a_skip_link(): void
    {
        // The first thing a keyboard user meets. Without it they tab through
        // the whole navigation on every page before reaching the content.
        $response = $this->get(route('home'));

        $response->assertOk();
        $response->assertSee('Skip to main content');
        $response->assertSee('id="main-content"', false);
    }

    public function test_the_audit_actually_catches_a_failure(): void
    {
        // A checker that passes everything is worse than none, because it is
        // believed. This proves it fails when it should.
        $audit = new AccessibilityAudit(<<<'HTML'
            <html><head></head><body>
                <img src="/a.png">
                <button></button>
                <input type="text" name="email" placeholder="Email">
                <a href="/x">click here</a>
                <div tabindex="3">focus me</div>
                <p id="dup"></p><p id="dup"></p>
            </body></html>
            HTML);

        $problems = $audit->problems();

        $this->assertNotSame([], $problems);

        $joined = implode("\n", $problems);

        foreach (['lang', '<title>', '<h1>', 'alt attribute', 'accessible name',
            'no label', 'does not say where it goes', 'positive tabindex',
            'used more than once', '<main> landmark'] as $expected) {
            $this->assertStringContainsString($expected, $joined, "Missed: {$expected}");
        }
    }

    public function test_the_audit_passes_well_formed_markup(): void
    {
        $audit = new AccessibilityAudit(<<<'HTML'
            <html lang="en"><head><title>A page</title></head><body>
                <main id="main-content">
                    <h1>A page</h1>
                    <h2>A section</h2>
                    <img src="/a.png" alt="A tour vehicle">
                    <img src="/decorative.png" alt="">
                    <button type="button">Save</button>
                    <button type="button" aria-label="Close the dialog"></button>
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email">
                    <label>Name <input type="text" name="name"></label>
                    <a href="/tours">Browse our tours</a>
                    <table>
                        <caption>Departures</caption>
                        <thead><tr><th scope="col">Date</th></tr></thead>
                        <tbody><tr><td>1 September</td></tr></tbody>
                    </table>
                </main>
            </body></html>
            HTML);

        $this->assertSame([], $audit->problems());
    }
}
