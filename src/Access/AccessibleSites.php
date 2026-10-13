<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Access;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Sites\Site as SiteObject;

/**
 * Refuses a sites selection that would leave the form outside its saver's edit (#118).
 *
 * Editing a form needs every site it lists, or every configured site for a global
 * form (#77), so a non-super user may not save a site they cannot access, nor an
 * empty selection, which makes the form global, unless they can access every
 * site. A super user, and a save with no signed-in user, pass. A handle naming no
 * configured site is left to `ConfiguredSites`.
 *
 * The rule is implicit, so it also runs when the selection is missing, which
 * saves the form as global just as an empty one does.
 */
final class AccessibleSites implements ValidationRule
{
    /** Run the rule when the value is missing or empty too. */
    public bool $implicit = true;

    /**
     * @param  Closure(string): mixed  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $user = User::current();
        if ($user === null || $user->isSuper()) {
            return;
        }

        $handles = array_values(array_filter((array) $value, is_string(...)));

        if ($handles === []) {
            if (! SiteAccess::canAccessAll($user, SiteAccess::allHandles())) {
                $fail('Choose the sites this form belongs to. Leaving :attribute empty would put it on every site, including sites you cannot access.');
            }

            return;
        }

        $refused = [];
        foreach ($handles as $handle) {
            $site = Site::get($handle);
            if ($site instanceof SiteObject && ! SiteAccess::canAccess($user, $handle)) {
                // Core leaves name() untyped; it falls back to the handle itself
                $name = $site->name();
                $refused[] = is_string($name) ? $name : $handle;
            }
        }

        if ($refused !== []) {
            $fail('This form cannot be assigned to a site you cannot access: '.implode(', ', $refused).'.');
        }
    }
}
