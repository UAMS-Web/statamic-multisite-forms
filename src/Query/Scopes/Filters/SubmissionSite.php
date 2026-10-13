<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Query\Scopes\Filters;

use Statamic\Facades\Form as FormFacade;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Forms\Form;
use Statamic\Query\Builder;
use Statamic\Query\EloquentQueryBuilder;
use Statamic\Query\Scopes\Filter;
use Statamic\Sites\Site as SiteObject;
use UAMSWeb\MultisiteForms\Access\SiteAccess;
use UAMSWeb\MultisiteForms\Forms\Assignment;
use UAMSWeb\MultisiteForms\Forms\RecordSubmitSite;

use function Statamic\trans as __;

/**
 * A Site filter on a form's submissions listing and its export (#58).
 *
 * Narrows the submissions to those recorded for one site, under `_site`. It
 * offers the sites the user can access that the form is assigned to (every site,
 * for a form with no sites), and appears only when that leaves more than one,
 * since with one there is nothing to choose between. Choosing a site leaves out
 * submissions with no recorded site; without a choice, the Control Panel's
 * submission scope (#78) decides which of those show, as before.
 *
 * Statamic registers it from `src/Query/Scopes/Filters` while booting the add-on.
 * Its handle, `submission_site`, keeps it apart from core's entries `site` filter.
 */
final class SubmissionSite extends Filter
{
    /**
     * The filter's title on the listing.
     */
    public static function title(): string
    {
        return self::label();
    }

    /**
     * Whether the filter appears for a listing: the form submissions one, with more than one site to offer.
     *
     * @param  string  $key
     */
    #[\Override]
    public function visibleTo($key): bool
    {
        return $key === 'form-submissions' && count($this->options()) > 1;
    }

    /**
     * One radio field, or a select for more than five sites, as core's entries filter has. Its label
     * comes from its handle, `site`, which Statamic displays and translates as "Site".
     *
     * @return array<string, array<string, mixed>>
     */
    #[\Override]
    protected function fieldItems(): array
    {
        $options = $this->options();

        return [
            'site' => [
                'type' => count($options) > 5 ? 'select' : 'radio',
                'options' => $options,
            ],
        ];
    }

    /**
     * Narrow the query to the submissions recorded for the chosen site.
     *
     * A site that is not offered is applied as given, so it finds none of the
     * submissions the user can see rather than lifting the filter.
     *
     * @param  Builder|EloquentQueryBuilder  $query
     * @param  mixed  $values
     */
    #[\Override]
    public function apply($query, $values): void
    {
        $site = self::chosen($values);
        if ($site !== null) {
            $query->where(RecordSubmitSite::KEY, $site);
        }
    }

    /**
     * The active-filter badge: the chosen site's name, or none when no site is chosen.
     *
     * Core returns the badge for any handle a request names, so a site the filter
     * does not offer shows by its handle: its name is withheld, as the Control Panel
     * withholds the sites a user cannot access.
     *
     * @param  mixed  $values
     */
    #[\Override]
    public function badge($values): string
    {
        $site = self::chosen($values);
        if ($site === null) {
            return '';
        }

        return self::label().': '.($this->options()[$site] ?? $site);
    }

    /**
     * The sites offered, by handle and name, in configured order.
     *
     * @return array<string, string>
     */
    private function options(): array
    {
        $user = User::current();
        $handle = is_array($this->context) ? ($this->context['form'] ?? null) : null;
        $form = is_string($handle) ? FormFacade::find($handle) : null;
        if ($user === null || ! $form instanceof Form) {
            return [];
        }

        $accessible = SiteAccess::accessible($user);

        // The facade declares `mixed`; core's Sites::all() is a collection of sites keyed by handle
        /** @var iterable<string, SiteObject> $sites */
        $sites = Site::all();

        $options = [];
        foreach ($sites as $handle => $site) {
            if (in_array($handle, $accessible, true) && Assignment::covers($form, $site)) {
                $options[$handle] = self::nameOf($handle, $site);
            }
        }

        return $options;
    }

    /**
     * The site handle a filter value chooses, or null when it names none.
     */
    private static function chosen(mixed $values): ?string
    {
        $site = is_array($values) ? ($values['site'] ?? null) : null;

        return is_string($site) && $site !== '' ? $site : null;
    }

    /**
     * A site's translated name, as core's entries filter shows it.
     */
    private static function nameOf(string $handle, SiteObject $site): string
    {
        $name = __($site->name());

        return is_string($name) ? $name : $handle;
    }

    /**
     * The translated word "Site".
     */
    private static function label(): string
    {
        $label = __('Site');

        return is_string($label) ? $label : 'Site';
    }
}
