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
 * the route whether or not routes are cached. It is listed after
 * `SubstituteBindings` in the priority list, so it runs after binding even if
 * something places it earlier in the group; where a host's priority list leaves
 * `SubstituteBindings` out, it lands last in that list and its place in the
 * group decides, which is after the group's own `SubstituteBindings`.
 *
 * A group the HTTP kernel owns is changed through the kernel. The kernel's
 * group, priority and alias setters each copy the kernel's groups and priority
 * onto the router, replacing the router's; so the router's current groups and
 * priority are copied into the kernel first. Middleware other code pushed onto
 * the router alone is then kept, and a later kernel setter keeps the gate.
 */
final class SubmitGate
{
    public static function register(Router $router, KernelContract $kernel): void
    {
        if ($kernel instanceof Kernel) {
            self::adoptRouterState($router, $kernel);
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

    /**
     * Copy the router's version of each kernel-owned group, and the router's
     * priority list, into the kernel, so the kernel's next copy to the router
     * writes back what is already there.
     */
    private static function adoptRouterState(Router $router, Kernel $kernel): void
    {
        // Read both before either setter runs: each one copies the kernel's state
        // onto the router, which would overwrite what is still to be read.
        $routerGroups = $router->getMiddlewareGroups();
        $routerPriority = $router->middlewarePriority;

        $groups = [];
        foreach ($kernel->getMiddlewareGroups() as $name => $middleware) {
            $groups[$name] = $routerGroups[$name] ?? $middleware;
        }

        $kernel->setMiddlewareGroups($groups);
        $kernel->setMiddlewarePriority($routerPriority);
    }
}
