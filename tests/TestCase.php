<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Tests;

use Illuminate\Foundation\Application;
use Statamic\Testing\AddonTestCase;
use UAMSWeb\MultisiteForms\ServiceProvider;

/**
 * Base class for the Integration suite: boots a minimal Statamic through
 * Orchestra Testbench with this add-on's provider registered.
 */
abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;

    /**
     * Give each parallel worker its own service manifest before the application boots.
     *
     * Every worker boots the same Testbench skeleton, whose `bootstrap/cache/services.php`
     * each one rewrites when it is missing or stale. On Windows, replacing a file another
     * process has open fails with "Access is denied", failing whichever test was booting
     * (#88). Testbench already names the package manifest per worker
     * (`test_{token}_packages.php`); this does the same for the service manifest. The
     * path is relative, so it resolves against the skeleton: Laravel treats a Windows
     * drive path as relative too.
     */
    protected function setUp(): void
    {
        $token = $_SERVER['TEST_TOKEN'] ?? null;
        if (is_string($token) || is_int($token)) {
            $_SERVER['APP_SERVICES_CACHE'] = $_ENV['APP_SERVICES_CACHE'] = "bootstrap/cache/test_{$token}_services.php";
        }

        parent::setUp();
    }

    /**
     * Give the application an encryption key, which a Control Panel request needs for its session.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }
}
