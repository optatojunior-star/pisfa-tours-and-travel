<?php

namespace Tests\Feature\Operations;

use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The preflight is a gate, so its exit code has to be trustworthy.
 *
 * Each test sets up one misconfiguration that is easy to make on shared
 * hosting, invisible from a browser, and expensive to find later.
 */
class ProductionPreflightTest extends TestCase
{
    use RefreshDatabase;

    /** Puts the configuration in the state a correct production deployment has. */
    private function configureAsProduction(): void
    {
        config()->set('app.env', 'production');
        config()->set('app.debug', false);
        config()->set('app.url', 'https://pisfatours.com');
        config()->set('session.secure', true);
        config()->set('session.encrypt', true);
        config()->set('mail.default', 'smtp');
        config()->set('mail.from.address', 'bookings@pisfatours.com');
        config()->set('queue.default', 'database');
    }

    public function test_a_correctly_configured_deployment_passes(): void
    {
        $this->configureAsProduction();

        $this->artisan('pisfa:preflight')->assertSuccessful();
    }

    public function test_debug_mode_fails_the_gate(): void
    {
        $this->configureAsProduction();
        config()->set('app.debug', true);

        // A debug page prints the stack trace, the query, and the environment.
        // On a public site that is a credential dump behind any 500.
        $this->artisan('pisfa:preflight')->assertFailed();
    }

    public function test_debug_mode_can_be_allowed_deliberately_for_staging(): void
    {
        $this->configureAsProduction();
        config()->set('app.debug', true);

        $this->artisan('pisfa:preflight --allow-debug')->assertSuccessful();
    }

    public function test_a_plain_http_url_fails_the_gate(): void
    {
        $this->configureAsProduction();
        config()->set('app.url', 'http://pisfatours.com');

        $this->artisan('pisfa:preflight')->assertFailed();
    }

    public function test_a_url_still_pointing_at_localhost_fails(): void
    {
        $this->configureAsProduction();
        config()->set('app.url', 'https://localhost');

        $this->artisan('pisfa:preflight')->assertFailed();
    }

    public function test_an_insecure_session_cookie_on_https_fails(): void
    {
        $this->configureAsProduction();
        config()->set('session.secure', false);

        // The session cookie would travel in clear text.
        $this->artisan('pisfa:preflight')->assertFailed();
    }

    public function test_a_missing_application_key_fails(): void
    {
        $this->configureAsProduction();
        config()->set('app.key', '');

        $this->artisan('pisfa:preflight')->assertFailed();
    }

    public function test_the_log_mailer_fails_because_nothing_would_reach_a_customer(): void
    {
        $this->configureAsProduction();
        config()->set('mail.default', 'log');

        $this->artisan('pisfa:preflight')->assertFailed();
    }

    public function test_a_missing_from_address_fails(): void
    {
        $this->configureAsProduction();
        config()->set('mail.from.address', null);

        $this->artisan('pisfa:preflight')->assertFailed();
    }

    public function test_the_preflight_changes_nothing(): void
    {
        $this->configureAsProduction();

        $before = [
            'users' => User::query()->count(),
            'settings' => Setting::query()->count(),
        ];

        $this->artisan('pisfa:preflight')->assertSuccessful();

        $this->assertSame($before, [
            'users' => User::query()->count(),
            'settings' => Setting::query()->count(),
        ], 'The preflight is meant to be read-only.');
    }
}
