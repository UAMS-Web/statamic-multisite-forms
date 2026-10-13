<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Sites;

use Illuminate\Support\Facades\URL;
use LogicException;
use Statamic\Facades\Site as SiteFacade;
use Statamic\Sites\Site;

/**
 * Names the site a request belongs to, the same way core does.
 *
 * The site that gates a submission, the site recorded on it, and the site core
 * passes to the email job must not disagree, so the submit rule is core's own
 * expression from `FormController::submit()`, kept in this one place. It trusts
 * the referrer: a missing or stripped referrer resolves to the default site.
 */
final class RequestSite
{
    /**
     * The site of a form submission: the site whose URL the referrer falls under, else the default.
     *
     * @throws LogicException When the install defines no site at all.
     */
    public function forSubmission(): Site
    {
        return self::site(SiteFacade::findByUrl(URL::previous()) ?? SiteFacade::default());
    }

    /**
     * The site of a frontend page render.
     *
     * @throws LogicException When the install defines no site at all.
     */
    public function forPage(): Site
    {
        return self::site(SiteFacade::current());
    }

    /**
     * The handle of a site given as an object or a handle.
     *
     * @throws LogicException When a site object carries no string handle.
     */
    public static function handle(Site|string $site): string
    {
        if (is_string($site)) {
            return $site;
        }

        $handle = $site->handle();
        if (! is_string($handle)) {
            throw new LogicException('A site has no handle; check the sites configuration.');
        }

        return $handle;
    }

    /**
     * Narrow the facade's untyped answer, which is null when no site is configured.
     *
     * @throws LogicException When it is not a site.
     */
    private static function site(mixed $site): Site
    {
        if (! $site instanceof Site) {
            throw new LogicException('No site could be determined for the request; the install defines no sites.');
        }

        return $site;
    }
}
