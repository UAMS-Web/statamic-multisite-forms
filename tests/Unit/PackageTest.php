<?php

declare(strict_types=1);

use UAMSWeb\MultisiteForms\ServiceProvider;

it('lists its service provider in composer.json so the host discovers it', function (): void {
    $contents = file_get_contents(__DIR__.'/../../composer.json');
    expect($contents)->toBeString();

    /** @var array{extra: array{laravel: array{providers: list<string>}}} $manifest */
    $manifest = json_decode((string) $contents, true, flags: JSON_THROW_ON_ERROR);

    expect($manifest['extra']['laravel']['providers'])->toContain(ServiceProvider::class);
});
