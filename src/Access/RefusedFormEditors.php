<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Access;

use Illuminate\Support\Facades\Gate;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;

/**
 * The form fields editors the current user may not open (#124, #133).
 *
 * Core lists every form's fields editor in places that know nothing of sites: the
 * editor's breadcrumb, the blueprints index and the command palette. Each of them
 * drops the links to the editors named here, so a user sees no title of a form
 * whose editor the add-on's rule for changing a form refuses them (#116).
 *
 * Links are compared by their path alone. Core builds them as absolute URLs on
 * the request's scheme and host, and caches the command palette per user without
 * either, so a palette built on one of a multisite's domains is served on the
 * others with links that name the first.
 */
final class RefusedFormEditors
{
    /**
     * The paths of the refused editors, built as core builds its links to them.
     *
     * @return list<string>
     */
    public static function paths(): array
    {
        $refused = [];
        /** @var Form $form */
        foreach (FormFacade::all() as $form) {
            $path = self::pathOf(cp_route('blueprints.forms.edit', $form->handle()));
            if ($path !== null && Gate::denies('configure form fields', [$form])) {
                $refused[] = $path;
            }
        }

        return $refused;
    }

    /**
     * Whether a link, an array with its URL under the given key, points at one of the refused editors.
     *
     * @param  list<string>  $refused
     */
    public static function isRefused(mixed $link, array $refused, string $key = 'url'): bool
    {
        return is_array($link) && in_array(self::pathOf($link[$key] ?? null), $refused, true);
    }

    /**
     * A URL's path, or null for anything that is not a URL with one.
     */
    private static function pathOf(mixed $url): ?string
    {
        $path = is_string($url) ? parse_url($url, PHP_URL_PATH) : null;

        return is_string($path) ? $path : null;
    }
}
