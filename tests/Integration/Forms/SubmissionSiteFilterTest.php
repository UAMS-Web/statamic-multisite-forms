<?php

declare(strict_types=1);

use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Statamic\Auth\File\User as UserObject;
use Statamic\Facades\Form as FormFacade;
use Statamic\Facades\FormSubmission;
use Statamic\Facades\Scope;
use Statamic\Facades\Site;
use Statamic\Facades\Stache;
use Statamic\Forms\Form;
use Statamic\Stache\Stores\Store;
use UAMSWeb\MultisiteForms\Query\Scopes\Filters\SubmissionSite;
use UAMSWeb\MultisiteForms\Tests\TestCase;

/**
 * The Site filter on a form's submissions listing and export narrows them to one
 * recorded site, offering only the sites the user can access that the form is
 * assigned to (#58).
 *
 * @command  vendor/bin/pest tests/Integration/Forms/SubmissionSiteFilterTest.php
 */

/** The forms every test stores, by handle, with the sites each lists. */
const FILTER_FORMS = [
    'shared' => ['en', 'es'],
    'english' => ['en'],
    'everywhere' => [],
];

beforeEach(function (): void {
    useTwoSites();
    $directory = useTemporaryFormsDirectory();
    $this->rolesFile = useTemporaryRoles();

    $store = Stache::store('form-submissions');
    if (! $store instanceof Store) {
        throw new RuntimeException('The Stache has no form submissions store.');
    }

    $store->directory($directory.'/_submissions');

    foreach (FILTER_FORMS as $handle => $sites) {
        $form = FormFacade::make($handle);
        if (! $form instanceof Form) {
            throw new RuntimeException('The forms repository did not make a form.');
        }

        $form->title(ucfirst($handle));
        if ($sites !== []) {
            $form->set('sites', $sites);
        }

        $form->save();

        // One submission per site, and one with no site, named after the site
        foreach (['en', 'es', null] as $site) {
            $submission = $form->makeSubmission();
            $submission->set('name', $site ?? 'none');
            if ($site !== null) {
                $submission->set('_site', $site);
            }

            $submission->save();
        }
    }
});

afterEach(function (): void {
    removeFormsDirectory();
    File::delete($this->rolesFile);
});

/**
 * A user who can view every form's submissions, with access to the given sites.
 */
function filterUser(string $id, string ...$sites): UserObject
{
    $permissions = ['access cp'];
    foreach (array_keys(FILTER_FORMS) as $handle) {
        $permissions[] = "view {$handle} form submissions";
    }

    foreach ($sites as $site) {
        $permissions[] = "access {$site} site";
    }

    return userWith($id, $permissions);
}

/**
 * A Control Panel URL, with the given filters encoded as the listing sends them.
 *
 * @param  array<array-key, string>|string  $parameters
 * @param  array<string, mixed>|null  $filters
 *
 * @throws RuntimeException When the route does not resolve to a URL.
 */
function filterUrl(string $route, array|string $parameters, ?array $filters = null): string
{
    $url = cp_route($route, $parameters);
    if (! is_string($url)) {
        throw new RuntimeException("The [{$route}] route did not resolve.");
    }

    return $filters === null ? $url : $url.'?filters='.base64_encode((string) json_encode($filters));
}

/**
 * The names of the submissions with the given ids, read outside the Control Panel, sorted.
 *
 * @return list<string>
 */
function filteredNames(mixed $rows): array
{
    if (! is_array($rows)) {
        throw new RuntimeException('The rows are not a list.');
    }

    Container::getInstance()->instance('request', Request::create('/'));

    $names = [];
    foreach ($rows as $row) {
        $id = is_array($row) ? ($row['id'] ?? null) : null;
        $name = is_string($id) ? FormSubmission::find($id)->get('name') : null;
        if (! is_string($name)) {
            throw new RuntimeException('A row names no stored submission.');
        }

        $names[] = $name;
    }

    sort($names);

    return $names;
}

/**
 * The submissions listing of a form, filtered or not, by name.
 *
 * @param  array<string, mixed>|null  $filters
 * @return list<string>
 */
function listingNames(TestCase $test, string $form, ?array $filters = null): array
{
    return filteredNames($test->getJson(filterUrl('forms.submissions.index', $form, $filters))->assertOk()->json('data'));
}

/**
 * The filters the submissions listing of a form offers, keyed by handle.
 *
 * @return array<array-key, mixed>
 */
function offeredFilters(string $form): array
{
    $filters = Scope::filters('form-submissions', ['form' => $form]);
    if (! is_iterable($filters)) {
        throw new RuntimeException('The listing filters are not a list.');
    }

    $offered = [];
    foreach ($filters as $filter) {
        if ($filter instanceof SubmissionSite) {
            $offered['submission_site'] = $filter->toArray();
        }
    }

    return $offered;
}

/**
 * The Site filter with the listing context of a form, or with none.
 */
function siteFilterFor(?string $form): SubmissionSite
{
    $filter = new SubmissionSite;
    if ($form !== null) {
        $filter->context(['form' => $form]);
    }

    return $filter;
}

it('narrows the listing to the chosen site', function (string $site, array $names): void {
    $this->actingAs(filterUser('both', 'en', 'es'));

    expect(listingNames($this, 'shared', ['submission_site' => ['site' => $site]]))->toBe($names);
})->with([
    'en' => ['en', ['en']],
    'es' => ['es', ['es']],
]);

it('leaves the listing to the submission scope when no site is chosen', function (): void {
    $this->actingAs(filterUser('both', 'en', 'es'));
    expect(listingNames($this, 'shared'))->toBe(['en', 'es', 'none']);

    $this->actingAs(filterUser('es-only', 'es'));
    expect(listingNames($this, 'everywhere'))->toBe(['es']);
});

it('finds nothing for a site the user cannot access, rather than lifting the filter', function (): void {
    $this->actingAs(filterUser('es-only', 'es'));

    expect(listingNames($this, 'everywhere', ['submission_site' => ['site' => 'en']]))->toBe([]);
});

it('ignores a filter value that names no site', function (mixed $values): void {
    $this->actingAs(filterUser('both', 'en', 'es'));

    expect(listingNames($this, 'shared', ['submission_site' => $values]))->toBe(['en', 'es', 'none']);
})->with([
    'an empty site' => [['site' => '']],
    'no site key' => [['other' => 'en']],
    'not a list' => ['en'],
]);

it('exports only the chosen site', function (): void {
    $this->actingAs(filterUser('both', 'en', 'es'));

    $response = $this->get(filterUrl('forms.export', ['shared', 'json'], ['submission_site' => ['site' => 'es']]))->assertOk();

    expect(filteredNames(json_decode((string) $response->getContent(), true)))->toBe(['es']);
});

it('offers the sites the user can access that the form is assigned to', function (): void {
    $this->actingAs(filterUser('both', 'en', 'es'));

    $offered = offeredFilters('shared');
    expect($offered)->toHaveKey('submission_site')
        ->and(data_get($offered, 'submission_site.fields.0.options'))->toBe(['en' => 'English', 'es' => 'Spanish'])
        ->and(data_get($offered, 'submission_site.fields.0.type'))->toBe('radio')
        ->and(data_get($offered, 'submission_site.title'))->toBe('Site')
        ->and(data_get($offered, 'submission_site.fields.0.display'))->toBe('Site');

    expect(offeredFilters('everywhere'))->toHaveKey('submission_site');
});

it('is not offered with fewer than two sites to choose between', function (string $id, string $sites, string $form): void {
    $this->actingAs(filterUser($id, ...explode(',', $sites)));

    expect(offeredFilters($form))->toBe([]);
})->with([
    'a user with one site' => ['es-only', 'es', 'shared'],
    'a form with one site' => ['both', 'en,es', 'english'],
]);

it('is not offered on another listing, for an unknown form, or with no user', function (): void {
    $this->actingAs(filterUser('both', 'en', 'es'));
    $filter = siteFilterFor('shared');

    expect($filter->visibleTo('form-submissions'))->toBeTrue()
        ->and($filter->visibleTo('entries'))->toBeFalse()
        ->and(siteFilterFor('missing')->visibleTo('form-submissions'))->toBeFalse()
        ->and(siteFilterFor(null)->visibleTo('form-submissions'))->toBeFalse();

    auth()->logout();
    expect($filter->visibleTo('form-submissions'))->toBeFalse();
});

it('offers a select instead of radio buttons beyond five sites', function (int $count, string $type): void {
    config(['statamic.editions.pro' => true, 'statamic.system.multisite' => true]);
    $sites = [];
    foreach (array_slice(['en', 'es', 'fr', 'de', 'it', 'pt'], 0, $count) as $handle) {
        $sites[$handle] = ['name' => strtoupper($handle), 'url' => "http://localhost/{$handle}/", 'locale' => $handle];
    }

    Site::setSites($sites);
    $this->actingAs(superUser());

    $fields = siteFilterFor('everywhere')->toArray()['fields'];
    expect(data_get($fields, '0.type'))->toBe($type)
        ->and(data_get($fields, '0.options'))->toHaveCount($count);
})->with([
    'five sites' => [5, 'radio'],
    'six sites' => [6, 'select'],
]);

it('translates its title, field label and badge', function (): void {
    app('translator')->addLines(['*.Site' => 'Sitio'], 'es');
    app()->setLocale('es');
    $this->actingAs(filterUser('both', 'en', 'es'));

    expect(SubmissionSite::title())->toBe('Sitio')
        ->and(data_get(siteFilterFor('shared')->toArray(), 'fields.0.display'))->toBe('Sitio')
        ->and((new SubmissionSite)->badge(['site' => 'es']))->toBe('Sitio: Spanish');
});

it('names the chosen site in its badge', function (mixed $values, string $badge): void {
    expect((new SubmissionSite)->badge($values))->toBe($badge);
})->with([
    'a configured site' => [['site' => 'es'], 'Site: Spanish'],
    'an unknown handle' => [['site' => 'gone'], 'Site: gone'],
    'no site' => [[], 'Site: '],
]);

it("is registered under its own handle, beside core's entries site filter", function (): void {
    expect(Scope::find('submission_site'))->toBeInstanceOf(SubmissionSite::class)
        ->and(Scope::find('site'))->toBeInstanceOf(Statamic\Query\Scopes\Filters\Site::class);
});
