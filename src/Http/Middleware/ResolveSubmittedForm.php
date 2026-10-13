<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Log;
use Statamic\Forms\Form;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use UAMSWeb\MultisiteForms\Forms\FormResolver;
use UAMSWeb\MultisiteForms\Forms\SiteForm;
use UAMSWeb\MultisiteForms\Sites\RequestSite;

/**
 * Gates core's form submit route by site assignment.
 *
 * It runs after `SubstituteBindings`, so the route's `form` parameter is already
 * the bound form. A form assigned to the submit site is replaced with its
 * read-only view for that site, which core's controller then submits; a form
 * not assigned to it gets the same 404 core gives a missing form, before
 * anything is stored. Every other route passes through untouched.
 */
final class ResolveSubmittedForm
{
    /** The name of core's form submit route. */
    public const string ROUTE = 'statamic.forms.submit';

    public function __construct(
        private readonly FormResolver $resolver,
        private readonly RequestSite $site,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     *
     * @throws NotFoundHttpException When the form is not assigned to the submit site.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();
        if (! $route instanceof Route || $route->getName() !== self::ROUTE) {
            return $next($request);
        }

        $form = $route->parameter('form');
        if (! $form instanceof Form) {
            // Core binds the form before this runs, so an unbound value means the
            // gate ran too early; core's controller handles the request as before.
            Log::warning('Multisite Forms did not gate a form submit by site: the form was not bound when the gate ran.', [
                'form' => $route->originalParameter('form'),
            ]);

            return $next($request);
        }

        $view = $this->resolver->resolve($form, $this->site->forSubmission());
        if (! $view instanceof SiteForm) {
            throw new NotFoundHttpException("Form [{$route->originalParameter('form')}] not found.");
        }

        $route->setParameter('form', $view);

        return $next($request);
    }
}
