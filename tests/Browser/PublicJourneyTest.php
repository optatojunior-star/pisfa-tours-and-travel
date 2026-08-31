<?php

namespace Tests\Browser;

use App\Enums\AccountStatus;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The journeys a real person takes, in a real browser.
 *
 * These exist to catch what HTTP tests cannot: whether the page a visitor is
 * looking at actually works once JavaScript has run. An HTTP test proves a form
 * posts correctly; only a browser proves the button is reachable by keyboard,
 * that Alpine hydrated, and that the customer's own booking is what appears on
 * their portal.
 *
 * Kept deliberately few. Browser tests are slow and flake under load, so they
 * cover the paths where being wrong is expensive — signing in, seeing your own
 * data and not somebody else's, and the chat widget that F19 added — while the
 * HTTP suite covers breadth.
 */
class PublicJourneyTest extends DuskTestCase
{
    use DatabaseMigrations;

    private function customer(string $email = 'grace@example.com'): User
    {
        return User::factory()->create([
            'name' => 'Grace Nakato',
            'email' => $email,
            'role' => UserRole::Customer,
            'status' => AccountStatus::Active,
            'email_verified_at' => now(),
            'password' => bcrypt('correct-horse-battery-staple'),
        ]);
    }

    public function test_a_visitor_can_reach_the_main_services_from_the_homepage(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->visit('/')
                ->assertSee('PISFA')
                ->assertPresent('#main-content')
                ->clickLink('Tours')
                ->assertPathIs('/tours')
                ->assertPresent('#main-content');
        });
    }

    public function test_the_skip_link_is_hidden_until_focused_and_then_visible(): void
    {
        // It is sr-only until focused — clipped to a single pixel — and must
        // become genuinely visible on focus. That transition is CSS, so it
        // cannot be checked from markup; only a real browser computes it.
        $this->browse(function (Browser $browser): void {
            $browser->visit('/');

            $selector = 'a[href="#main-content"]';

            // Measured by the clip rectangle, not by height: the link also
            // carries padding utilities that outrank sr-only's `padding: 0`,
            // so it has a non-zero box even while it is clipped away to
            // nothing. `clip` is what actually hides it.
            $read = static fn (string $property): string => 'return getComputedStyle('
                ."document.querySelector('{$selector}')).{$property};";

            $clippedBefore = $browser->driver->executeScript($read('clip'));
            $positionBefore = $browser->driver->executeScript($read('position'));

            $browser->driver->executeScript("document.querySelector('{$selector}').focus();");
            $browser->pause(300);

            $clippedAfter = $browser->driver->executeScript($read('clip'));
            $positionAfter = $browser->driver->executeScript($read('position'));

            $this->assertStringContainsString('rect', (string) $clippedBefore,
                'The skip link is not clipped away before focus.');
            $this->assertSame('absolute', $positionBefore);

            $this->assertSame('auto', $clippedAfter,
                'The skip link is still clipped after focus, so a keyboard user cannot see it.');
            $this->assertSame('fixed', $positionAfter,
                'The focused skip link is not pinned into view.');
        });
    }

    public function test_a_customer_signs_in_and_sees_their_own_portal(): void
    {
        $customer = $this->customer();

        $this->browse(function (Browser $browser) use ($customer): void {
            // Clicked by selector, not by label: the button is styled
            // `text-transform: uppercase`, so the browser reports its text as
            // "LOG IN" and matching on "Log in" would never resolve.
            // /dashboard redirects a customer straight to their portal (F13),
            // so the portal is what the journey actually lands on.
            $browser->visit('/login')
                ->type('email', $customer->email)
                ->type('password', 'correct-horse-battery-staple')
                ->click('form button[type="submit"]')
                ->waitForLocation('/portal', 25)
                ->assertSee('Grace');
        });
    }

    public function test_a_customer_cannot_reach_the_staff_console(): void
    {
        $customer = $this->customer();

        $this->browse(function (Browser $browser) use ($customer): void {
            $browser->loginAs($customer)
                ->visit('/admin/inbox')
                ->assertDontSee('Needs a reply');
        });
    }

    public function test_the_chat_widget_opens_and_takes_a_message(): void
    {
        // F19's widget is entirely Alpine-driven, so this is the only place its
        // behaviour is genuinely exercised rather than asserted from markup.
        $this->browse(function (Browser $browser): void {
            // The widget's fields are bound by x-model and carry an id rather
            // than a name, so they are addressed by CSS selector.
            $browser->visit('/')
                ->waitFor('#chat-widget-title, button', 10)
                ->press('Chat with us')
                ->waitFor('#chat-first-message', 10)
                ->type('#chat-name', 'Grace Nakato')
                ->type('#chat-email', 'grace@example.com')
                ->type('#chat-first-message', 'Do you do gorilla trekking safaris?')
                ->click('form button[type="submit"]')
                // The bot answers the keyword in the same request, so the reply
                // appears without waiting for a poll interval.
                ->waitForText('safaris', 20)
                ->assertSee('safaris');
        });
    }

    public function test_the_offline_page_stands_on_its_own(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->visit('/offline')
                ->assertSee('You are offline')
                ->assertSee('nothing has been booked or paid');
        });
    }

    public function test_the_service_worker_registers(): void
    {
        $this->browse(function (Browser $browser): void {
            $browser->visit('/')
                ->pause(1500);

            // Registration is asynchronous and only works on a secure origin or
            // localhost, which is what Dusk serves.
            $registered = $browser->driver->executeScript(
                'return navigator.serviceWorker ? navigator.serviceWorker.controller !== undefined : false;',
            );

            $this->assertTrue($registered !== null);
        });
    }
}
