<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms;

use Statamic\Providers\AddonServiceProvider;

/**
 * Service provider for the Multisite Forms add-on.
 *
 * The host application discovers it through `extra.laravel.providers` in
 * `composer.json`. It registers nothing yet; bindings and commands are added
 * as the phases in the add-on's specification land.
 */
class ServiceProvider extends AddonServiceProvider {}
