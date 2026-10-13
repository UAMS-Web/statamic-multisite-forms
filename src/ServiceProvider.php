<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms;

use Statamic\Providers\AddonServiceProvider;

/**
 * Service provider for the Multisite Forms add-on.
 *
 * The host application discovers it through `extra.laravel.providers` in
 * `composer.json`. It registers the Control Panel build and nothing else yet;
 * bindings and commands are added as the phases in the add-on's specification
 * land.
 */
class ServiceProvider extends AddonServiceProvider
{
    /**
     * The Vite build of the Control Panel assets. `input` and `publicDirectory`
     * must match `vite.config.js`; the built files are committed under
     * `resources/dist`.
     *
     * @var array{input: list<string>, publicDirectory: string}
     */
    protected $vite = [
        'input' => [
            'resources/js/cp.js',
            'resources/css/cp.css',
        ],
        'publicDirectory' => 'resources/dist',
    ];
}
