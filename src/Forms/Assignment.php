<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Forms;

use Statamic\Forms\Form;
use Statamic\Sites\Site;
use UAMSWeb\MultisiteForms\Sites\RequestSite;

/**
 * Reads which sites a form is assigned to.
 *
 * The assignment is the `sites` key in the form file. A form with no `sites`
 * key, or an empty list, belongs to every site, which is how core treats every
 * form; that keeps a form the add-on never touched behaving exactly as before.
 */
final class Assignment
{
    /** The form-file key that holds the assignment. */
    public const string KEY = 'sites';

    /**
     * The site handles a form lists, in file order, without blanks or repeats.
     *
     * An empty result means the form is assigned to every site.
     *
     * @return list<string>
     */
    public static function sites(Form $form): array
    {
        $value = $form->get(self::KEY);

        // A single handle written as a string is one site, not a list of characters
        $handles = is_string($value) ? [$value] : (is_array($value) ? $value : []);

        // Keep only non-empty string handles, once each
        $sites = [];
        foreach ($handles as $handle) {
            if (is_string($handle) && $handle !== '' && ! in_array($handle, $sites, true)) {
                $sites[] = $handle;
            }
        }

        return $sites;
    }

    /**
     * Whether the form lists no sites, and so belongs to every site.
     */
    public static function isGlobal(Form $form): bool
    {
        return self::sites($form) === [];
    }

    /**
     * Whether the form is assigned to a site.
     */
    public static function covers(Form $form, Site|string $site): bool
    {
        $sites = self::sites($form);

        return $sites === [] || in_array(RequestSite::handle($site), $sites, true);
    }
}
