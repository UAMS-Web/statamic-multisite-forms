<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Forms;

use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;
use Statamic\Facades\Site;
use Statamic\Sites\Site as SiteObject;

/**
 * Validates the `site_overrides` rows submitted from a form's settings screen.
 *
 * Each row's site must be a configured site the form is assigned to (any site,
 * for a form with no sites), and no site may have two rows, because only the
 * first would ever apply. The assignment is read from the `sites` value in the
 * same request, so a row and the selection it depends on are checked together.
 * A row's site arrives as a one-item list from the screen and as a handle from
 * the form file; both are read. A row with no site is left to the row field's
 * own `required` rule.
 */
final class OverrideRows implements DataAwareRule, ValidationRule
{
    /** @var array<array-key, mixed> */
    private array $data = [];

    /**
     * @param  array<array-key, mixed>  $data
     */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    /**
     * @param  Closure(string): mixed  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $assigned = $this->assignedSites();
        $seen = [];

        foreach (is_array($value) ? $value : [] as $row) {
            $handle = self::siteOf($row);
            if ($handle === null) {
                continue;
            }

            $site = Site::get($handle);
            if (! $site instanceof SiteObject) {
                $fail("An override row names [{$handle}], which is not a site this install defines.");

                return;
            }

            $name = $site->name();
            $name = is_string($name) && $name !== '' ? $name : $handle;

            if ($assigned !== [] && ! in_array($handle, $assigned, true)) {
                $fail("{$name} has an override row but is not one of this form's sites.");

                return;
            }

            if (in_array($handle, $seen, true)) {
                $fail("{$name} has more than one override row. Keep one row per site.");

                return;
            }

            $seen[] = $handle;
        }
    }

    /**
     * The site handle a row names, or null when it names none.
     */
    private static function siteOf(mixed $row): ?string
    {
        $site = is_array($row) ? ($row['site'] ?? null) : null;

        if (is_array($site)) {
            $site = $site[0] ?? null;
        }

        return is_string($site) && $site !== '' ? $site : null;
    }

    /**
     * The sites the submitted selection assigns the form to; empty for a global form.
     *
     * @return list<string>
     */
    private function assignedSites(): array
    {
        $sites = $this->data[Assignment::KEY] ?? null;

        // The keys are only reindexed for the list type: the caller compares against
        // [] and searches values, so a keyed result behaves the same.
        // @pest-mutate-ignore: UnwrapArrayValues
        return array_values(array_filter(
            is_array($sites) ? $sites : (is_string($sites) ? [$sites] : []),
            fn (mixed $handle): bool => is_string($handle) && $handle !== '',
        ));
    }
}
