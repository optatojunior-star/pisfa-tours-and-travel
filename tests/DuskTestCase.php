<?php

namespace Tests;

use Facebook\WebDriver\Chrome\ChromeOptions;
use Facebook\WebDriver\Remote\DesiredCapabilities;
use Facebook\WebDriver\Remote\RemoteWebDriver;
use Illuminate\Support\Collection;
use Laravel\Dusk\TestCase as BaseTestCase;
use PHPUnit\Framework\Attributes\BeforeClass;

abstract class DuskTestCase extends BaseTestCase
{
    /**
     * Prepare for Dusk test execution.
     *
     * A driver that is already running is used as-is. That covers CI, where the
     * driver is a service container, and local Windows, where Dusk's own
     * process spawning does not reliably start the bundled binary — starting it
     * by hand and pointing DUSK_DRIVER_URL at it is the documented way out, and
     * silently starting a second one on the same port would only fail.
     */
    #[BeforeClass]
    public static function prepare(): void
    {
        if (static::runningInSail() || static::driverAlreadyRunning()) {
            return;
        }

        static::startChromeDriver(['--port=9515']);
    }

    /**
     * Where the WebDriver is listening.
     *
     * Read straight from the process environment rather than through `env()`,
     * which returns null once configuration is cached — and this is a
     * test-runner setting, not application configuration, so it has no config
     * key to read instead.
     */
    protected static function driverUrl(): string
    {
        $url = $_ENV['DUSK_DRIVER_URL']
            ?? $_SERVER['DUSK_DRIVER_URL']
            ?? getenv('DUSK_DRIVER_URL');

        return is_string($url) && $url !== '' ? $url : 'http://localhost:9515';
    }

    /** Whether something is already answering on the driver port. */
    protected static function driverAlreadyRunning(): bool
    {
        $parts = parse_url(static::driverUrl());

        $connection = @fsockopen(
            $parts['host'] ?? 'localhost',
            (int) ($parts['port'] ?? 9515),
            $errorCode,
            $errorMessage,
            1,
        );

        if ($connection === false) {
            return false;
        }

        fclose($connection);

        return true;
    }

    /**
     * Create the RemoteWebDriver instance.
     */
    protected function driver(): RemoteWebDriver
    {
        $options = (new ChromeOptions)->addArguments(collect([
            $this->shouldStartMaximized() ? '--start-maximized' : '--window-size=1920,1080',
            '--disable-search-engine-choice-screen',
            '--disable-smooth-scrolling',
        ])->unless($this->hasHeadlessDisabled(), function (Collection $items) {
            return $items->merge([
                '--disable-gpu',
                '--headless=new',
            ]);
        })->all());

        return RemoteWebDriver::create(
            static::driverUrl(),
            DesiredCapabilities::chrome()->setCapability(
                ChromeOptions::CAPABILITY, $options
            )
        );
    }
}
