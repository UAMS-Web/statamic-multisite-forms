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
     * Give the application an encryption key, which a Control Panel request needs for its session.
     *
     * @param  Application  $app
     */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }
}
