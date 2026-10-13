<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Forms;

use Illuminate\Pipeline\Pipeline;
use Illuminate\Support\Collection;
use ReflectionClass;
use Statamic\Forms\Form;
use UAMSWeb\MultisiteForms\Exceptions\ReadOnlyFormException;

/**
 * A read-only view of a form as one site sees it.
 *
 * It holds a copy of the base form's state, so the handle, the blueprint, the
 * action URL and the submissions folder are the base form's, and it remembers
 * the site it was resolved for. Saving or deleting it throws: a resolved view
 * written back would put one site's values into the base file for every site.
 * Build one with {@see FormResolver}, not directly.
 */
class SiteForm extends Form
{
    /** The handle of the site this view was resolved for. */
    private string $site;

    /** The base form this view was copied from. */
    private Form $base;

    /**
     * A view of a base form for a site, with the base form left untouched.
     */
    public static function of(Form $base, string $site): self
    {
        // Copy from a clone, so the data and supplements collections are not shared with the base
        $source = clone $base;
        $view = new self;

        // Copy every property core's Form declares, its traits' included
        foreach (new ReflectionClass(Form::class)->getProperties() as $property) {
            // Skip what cannot be copied: a static property, and a typed property with no
            // default, which throws when read. Core's Form declares neither today, so both
            // halves are always true and swapping && for || cannot be told apart.
            // @pest-mutate-ignore: BooleanAndToBooleanOr
            if (! $property->isStatic() && $property->isInitialized($source)) {
                $property->setValue($view, $property->getValue($source));
            }
        }

        $view->site = $site;
        $view->base = $base;

        return $view;
    }

    /**
     * The handle of the site this view was resolved for.
     */
    public function site(): string
    {
        return $this->site;
    }

    /**
     * The base form this view was copied from.
     */
    public function base(): Form
    {
        return $this->base;
    }

    /**
     * Refuse to save: the view's values belong to one site.
     *
     * @throws ReadOnlyFormException
     */
    public function save(): never
    {
        throw ReadOnlyFormException::for('saved', $this->handleName(), $this->site);
    }

    /**
     * Refuse to delete: deleting the view would delete the base form and its submissions.
     *
     * @throws ReadOnlyFormException
     */
    public function delete(): never
    {
        throw ReadOnlyFormException::for('deleted', $this->handleName(), $this->site);
    }

    /**
     * Run the hooks registered on core's Form, then those registered on this class.
     *
     * Core files a hook under the class it was registered on and runs only the
     * hooks filed under `static::class`, so without this a hook an add-on or app
     * registered with `Form::hook()` would silently skip every resolved view.
     *
     * @param  mixed  $payload
     * @return mixed
     */
    protected function runHooks(string $name, $payload = null)
    {
        $hooks = app('statamic.hooks');

        $closures = Collection::wrap(data_get($hooks, [Form::class, $name], []))
            ->merge(Collection::wrap(data_get($hooks, [static::class, $name], [])))
            ->map->bindTo($this, $this);

        return (new Pipeline)
            ->send($payload)
            ->through($closures->all())
            ->thenReturn();
    }

    /**
     * The form handle for a message, which core's untyped getter does not promise is a string.
     */
    private function handleName(): string
    {
        $handle = $this->handle();

        return is_string($handle) ? $handle : '';
    }
}
