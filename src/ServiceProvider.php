<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms;

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Router;
use Statamic\Providers\AddonServiceProvider;
use UAMSWeb\MultisiteForms\Forms\ConfigFields;
use UAMSWeb\MultisiteForms\Http\SubmitGate;

/**
 * Service provider for the Multisite Forms add-on.
 *
 * The host application discovers it through `extra.laravel.providers` in
 * `composer.json`. It registers the Control Panel build, the Sites section of
 * the form settings screen, and the gate on core's form submit route; the rest is added as the phases in the add-on's
 * specification land.
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

    /**
     * Boot the add-on once Statamic has booted.
     */
    public function bootAddon(): void
    {
        ConfigFields::register();
        SubmitGate::register($this->app->make(Router::class), $this->app->make(Kernel::class));
    }
}
