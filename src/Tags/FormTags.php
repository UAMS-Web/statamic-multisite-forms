<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Tags;

use Statamic\Facades\Blink;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use Statamic\Forms\Tags;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use UAMSWeb\MultisiteForms\Forms\FormResolver;
use UAMSWeb\MultisiteForms\Forms\NotAssigned;
use UAMSWeb\MultisiteForms\Forms\SiteForm;
use UAMSWeb\MultisiteForms\Sites\RequestSite;

/**
 * Core's `{{ form }}` tags, gated by site assignment.
 *
 * It registers under core's own handle, `form`, so it replaces core's tags. Each
 * tag that reads a form resolves it for the page's site first: an assigned form,
 * or one with no sites, renders as core renders it, through the site's read-only
 * view; an unassigned form follows the `not_assigned` setting.
 *
 * Seeding core's `form-{handle}` Blink key with the site's view (the spec's first
 * mechanism) is not enough on its own: core puts a form passed in from context
 * straight into that key, replacing a seed, and a missing form makes core throw
 * rather than render nothing. So the form is resolved here, from the base form
 * core finds, every time a tag asks for it.
 *
 * Each tag returns whatever core's returns, which is wider than core's docblocks
 * say (`create` returns an array when the tag has no content), hence `mixed`; for
 * an unassigned form it returns a value that renders nothing: an empty string,
 * `false` for the tags core answers with a boolean, or an empty list.
 */
class FormTags extends Tags
{
    /**
     * The form tags' handle, which is core's.
     *
     * @var string
     */
    protected static $handle = 'form';

    /**
     * `{{ form:create }}`, and `{{ form:{handle} }}`, which core's `__call` renders through it.
     */
    public function create(): mixed
    {
        return $this->gated() ? '' : parent::create();
    }

    public function fields(): mixed
    {
        return $this->gated() ? '' : parent::fields();
    }

    public function errors(): mixed
    {
        return $this->gated() ? false : parent::errors();
    }

    public function success(): mixed
    {
        return $this->gated() ? false : parent::success();
    }

    /**
     * @return array<array-key, mixed>|null
     */
    public function submission(): mixed
    {
        if ($this->gated()) {
            return [];
        }

        $submission = parent::submission();

        return is_array($submission) ? $submission : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    public function submissions(): mixed
    {
        return $this->gated() ? [] : parent::submissions();
    }

    /**
     * The form for the page's site: the site's read-only view, or null when the
     * form is unassigned or missing.
     */
    protected function form(): ?SiteForm
    {
        $handle = $this->namedHandle();
        $base = $handle === null ? null : $this->baseForm($handle);

        return $base instanceof Form ? $this->resolve($base) : null;
    }

    /**
     * Whether the tag's form is not assigned to the page's site, so the tag must
     * render nothing; under the `404` setting it aborts instead. A missing form
     * is not gated, so core reports it.
     *
     * @throws NotFoundHttpException When the form is unassigned and the setting is `404`.
     */
    private function gated(): bool
    {
        $handle = $this->namedHandle();
        $base = $handle === null ? null : $this->baseForm($handle);
        if ($handle === null || ! $base instanceof Form || $this->resolve($base) instanceof SiteForm) {
            return false;
        }

        $site = RequestSite::handle(app(RequestSite::class)->forPage());

        if (NotAssigned::fromConfig() === NotAssigned::NotFound) {
            throw new NotFoundHttpException("Form [{$handle}] is not assigned to site [{$site}].");
        }

        // Once per form and site per request: Blink marks the key as seen even
        // though the callback stores nothing.
        Blink::once("multisite-forms.not-assigned.{$handle}.{$site}", function () use ($handle, $site): void {
            logger()->warning("Multisite Forms rendered nothing for a form not assigned to the page's site.", [
                'form' => $handle,
                'site' => $site,
            ]);
        });

        return true;
    }

    /**
     * The handle the tag names, from its parameters or a form in context, or null.
     */
    private function namedHandle(): ?string
    {
        $handle = $this->formHandle();

        return is_string($handle) ? $handle : null;
    }

    /**
     * The form with that handle, found and cached the way core finds it. It may
     * already be a site's view, which the resolver resolves from its base.
     */
    private function baseForm(string $handle): mixed
    {
        return Blink::once("form-{$handle}", fn (): mixed => FormFacade::find($handle));
    }

    private function resolve(Form $base): ?SiteForm
    {
        return app(FormResolver::class)->resolve($base, app(RequestSite::class)->forPage());
    }
}
