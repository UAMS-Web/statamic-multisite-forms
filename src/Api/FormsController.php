<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Api;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Statamic\Exceptions\ApiValidationException;
use Statamic\Facades\Form as FormFacade;
use Statamic\Facades\Site;
use Statamic\Forms\Form;
use Statamic\Http\Controllers\API\FormsController as CoreFormsController;
use Statamic\Http\Resources\API\FormResource;
use Statamic\Sites\Site as SiteObject;
use UAMSWeb\MultisiteForms\Forms\Assignment;

/**
 * The REST `forms` listing, with an optional `site` filter (#80).
 *
 * `GET /api/forms?site=es` lists only the forms assigned to `es`, including forms
 * with no sites, which belong to every site. Without the parameter the listing is
 * core's. A `site` that names no configured site is a validation error, so a typo
 * answers 422 rather than an empty list. The single-form endpoint is core's.
 *
 * The container builds core's controller for its route, so binding this subclass
 * in its place adds the filter without touching the route.
 */
final class FormsController extends CoreFormsController
{
    /**
     * Use this controller wherever core's REST forms controller is built.
     */
    public static function register(Application $app): void
    {
        $app->bind(CoreFormsController::class, self::class);
    }

    /**
     * The forms the API allows, narrowed to a site when the request names one.
     */
    #[\Override]
    public function index(): AnonymousResourceCollection
    {
        $this->abortIfDisabled();

        /** @var Collection<array-key, Form> $forms */
        $forms = $this->filterAllowedResources(FormFacade::all());

        $site = self::requestedSiteFilter();
        if ($site instanceof SiteObject) {
            $forms = $forms->filter(static fn (Form $form): bool => Assignment::covers($form, $site))->values();
        }

        // Core resolves the resource from the container too, so a host's own binding still applies
        /** @var FormResource $resource */
        $resource = app(FormResource::class);

        return $resource::collection($forms);
    }

    /**
     * The site the request filters by, or null when it names none.
     *
     * @throws ApiValidationException When the `site` parameter names no configured site.
     */
    private static function requestedSiteFilter(): ?SiteObject
    {
        if (! request()->has('site')) {
            return null;
        }

        $handle = request()->input('site');
        $site = is_string($handle) ? Site::get($handle) : null;
        if (! $site instanceof SiteObject) {
            throw ApiValidationException::withMessages([
                'site' => 'The site filter names no configured site.',
            ]);
        }

        return $site;
    }
}
