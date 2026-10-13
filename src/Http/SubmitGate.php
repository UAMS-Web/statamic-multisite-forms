<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Http;

use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Router;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use UAMSWeb\MultisiteForms\Http\Middleware\ResolveSubmittedForm;

/**
 * Attaches the submit gate to core's front-end routes.
 *
 * Core loads its front-end routes, the form submit route among them, under the
 * middleware named by `statamic.routes.middleware` (`web` by default). The gate
 * is appended to each of those that is a middleware group, so it travels with
 * the route whether or not routes are cached, and it is placed after
 * `SubstituteBindings` in the priority list, so it always sees the bound form.
 * The middleware itself acts only on the submit route.
 *
 * A group the HTTP kernel owns is changed through the kernel: the kernel copies
 * its groups onto the router whenever its own middleware changes, so a change
 * made on the router alone would be undone by the next such call.
 */
final class SubmitGate
{
    public static function register(Router $router, KernelContract $kernel): void
    {
        if ($kernel instanceof Kernel) {
            $kernel->addToMiddlewarePriorityAfter(SubstituteBindings::class, ResolveSubmittedForm::class);
        }

        $attached = false;
        foreach (Arr::wrap(config('statamic.routes.middleware', 'web')) as $group) {
            if (! is_string($group)) {
                continue;
            }

            if ($kernel instanceof Kernel && array_key_exists($group, $kernel->getMiddlewareGroups())) {
                $kernel->appendMiddlewareToGroup($group, ResolveSubmittedForm::class);
                $attached = true;
            } elseif ($router->hasMiddlewareGroup($group)) {
                $router->pushMiddlewareToGroup($group, ResolveSubmittedForm::class);
                $attached = true;
            }
        }

        if (! $attached) {
            Log::warning('Multisite Forms could not gate form submits by site: statamic.routes.middleware names no middleware group, so forms accept submits from every site.');
        }
    }
}
