<?php

declare(strict_types=1);

use UAMSWeb\MultisiteForms\ServiceProvider;

it('registers its service provider with the application', function (): void {
    expect(app()->getProvider(ServiceProvider::class))->toBeInstanceOf(ServiceProvider::class);
});
