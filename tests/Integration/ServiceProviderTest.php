<?php

declare(strict_types=1);

use UAMSWeb\MultisiteForms\ServiceProvider;

it('registers its service provider with the application', function (): void {
    expect(app()->getProvider(ServiceProvider::class))->toBeInstanceOf(ServiceProvider::class);
});

it('publishes its config file under the tag the README names', function (): void {
    $paths = ServiceProvider::pathsToPublish(null, 'statamic-multisite-forms-config');

    expect(array_map(realpath(...), array_keys($paths)))->toBe([realpath(__DIR__.'/../../config/statamic-multisite-forms.php')])
        ->and(array_values($paths))->toBe([config_path('statamic-multisite-forms.php')]);
});
