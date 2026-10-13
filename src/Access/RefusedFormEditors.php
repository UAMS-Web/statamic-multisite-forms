<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Access;

use Illuminate\Support\Facades\Gate;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;

/**
 * The URLs of the form fields editors the current user may not open (#124, #133).
 *
 * Core lists every form's fields editor in places that know nothing of sites: the
 * editor's breadcrumb, the blueprints index and the command palette. Each of them
 * drops the links named here, so a user sees no title of a form whose editor the
 * add-on's rule for changing a form refuses them (#116).
 */
final class RefusedFormEditors
{
    /**
     * The editor URLs, built as core builds its links to them.
     *
     * @return list<mixed>
     */
    public static function urls(): array
    {
        $refused = [];
        /** @var Form $form */
        foreach (FormFacade::all() as $form) {
            if (Gate::denies('configure form fields', [$form])) {
                $refused[] = cp_route('blueprints.forms.edit', $form->handle());
            }
        }

        return $refused;
    }

    /**
     * Whether a link, an array with its URL under the given key, points at one of the refused editors.
     *
     * @param  list<mixed>  $refused
     */
    public static function isRefused(mixed $link, array $refused, string $key = 'url'): bool
    {
        return is_array($link) && in_array($link[$key] ?? null, $refused, true);
    }
}
