<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Tests;

use Statamic\Testing\AddonTestCase;
use UAMSWeb\MultisiteForms\ServiceProvider;

/**
 * Base class for the Integration suite: boots a minimal Statamic through
 * Orchestra Testbench with this add-on's provider registered.
 */
abstract class TestCase extends AddonTestCase
{
    protected string $addonServiceProvider = ServiceProvider::class;
}
