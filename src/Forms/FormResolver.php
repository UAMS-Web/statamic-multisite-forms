<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Forms;

use Statamic\Forms\Form;
use Statamic\Sites\Site;
use UAMSWeb\MultisiteForms\Sites\RequestSite;

/**
 * Resolves a form for a site.
 *
 * Every surface that shows or accepts a form for a site (the tags, the submit
 * route, the APIs) asks this class, so assignment and the site's overrides are
 * decided in one place. The view carries the base form's values, with the
 * site's `site_overrides` row applied on top ({@see Overrides}).
 */
final class FormResolver
{
    /**
     * The form as the site sees it, or null when the form is not assigned to the site.
     */
    public function resolve(Form $form, Site|string $site): ?SiteForm
    {
        // Resolve from the base form, so a view is never resolved from another view
        $base = $form instanceof SiteForm ? $form->base() : $form;
        $handle = RequestSite::handle($site);

        if (! Assignment::covers($base, $handle)) {
            return null;
        }

        $view = SiteForm::of($base, $handle);
        Overrides::apply($view);

        return $view;
    }
}
