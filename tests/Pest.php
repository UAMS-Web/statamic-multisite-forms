<?php

/**
 * Pest configuration for the add-on.
 *
 * Integration tests boot a minimal Statamic through Orchestra Testbench via
 * `Tests\TestCase`; Unit tests stay on PHPUnit's own base class and boot
 * nothing. Shared helpers and custom expectations go here when the suite
 * grows them.
 */

declare(strict_types=1);

use Statamic\Facades\Site;
use Statamic\Sites\Site as SiteObject;
use UAMSWeb\MultisiteForms\Tests\TestCase;

pest()->extend(TestCase::class)->in('Integration');

/**
 * A configured site by handle, for a test that needs the site object.
 *
 * @throws RuntimeException When the test configured no site with that handle.
 */
function configuredSite(string $handle): SiteObject
{
    $site = Site::get($handle);
    if (! $site instanceof SiteObject) {
        throw new RuntimeException("The test configured no [{$handle}] site.");
    }

    return $site;
}
