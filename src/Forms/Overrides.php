<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Forms;

use Statamic\Forms\Form;

/**
 * Reads a form's per-site overrides and applies a site's to its resolved view.
 *
 * The overrides are the `site_overrides` key in the form file: a list of rows,
 * each naming a `site`, which is the shape a grid field saves. A site's row may
 * set `title`, `email` and `store`; a value it leaves out falls back to the base
 * form's. Any other key in a row is ignored, never an error.
 */
final class Overrides
{
    /** The form-file key that holds the rows. */
    public const string KEY = 'site_overrides';

    /** The `store` value the editor saves for "Store". */
    public const string STORE = 'true';

    /** The `store` value the editor saves for "Don't store". */
    public const string DO_NOT_STORE = 'false';

    /**
     * The first row for a site, or null when the form has none for it.
     *
     * Rows that are not lists of values, or that name no site, are skipped. A
     * second row for the same site is a validation error in the editor, so a
     * form that has one anyway resolves from the first.
     *
     * @return array<array-key, mixed>|null
     */
    public static function rowFor(Form $form, string $site): ?array
    {
        $rows = $form->get(self::KEY);

        if (! is_array($rows)) {
            return null;
        }

        foreach ($rows as $row) {
            if (is_array($row) && ($row['site'] ?? null) === $site) {
                return $row;
            }
        }

        return null;
    }

    /**
     * Set the site's overriding title, email and store on its view.
     *
     * A row value counts as set only when it holds something: an empty title or
     * an empty email list falls back to the base form's, so a cell the editor
     * left blank never clears a site's title or stops its email. An overriding
     * email replaces the base list whole, because merging two recipient lists
     * would mail people nobody named for that site. Store is read as a boolean
     * written by hand or as the `'true'` or `'false'` the editor's button group
     * saves; anything else falls back to the base form's.
     */
    public static function apply(SiteForm $view): void
    {
        $row = self::rowFor($view->base(), $view->site());

        if ($row === null) {
            return;
        }

        $title = $row['title'] ?? null;
        if (is_string($title) && $title !== '') {
            $view->title($title);
        }

        $email = $row['email'] ?? null;
        if (is_array($email) && $email !== []) {
            $view->email($email);
        }

        $store = self::storeOf($row['store'] ?? null);
        if ($store !== null) {
            $view->store($store);
        }
    }

    /**
     * A row's store setting as a boolean, or null when it sets none.
     */
    private static function storeOf(mixed $value): ?bool
    {
        return match ($value) {
            true, self::STORE => true,
            false, self::DO_NOT_STORE => false,
            default => null,
        };
    }
}
