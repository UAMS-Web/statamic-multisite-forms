<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Inertia\Testing\AssertableInertia;
use Statamic\Auth\File\User as UserObject;
use Statamic\Facades\CommandPalette;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\MultisiteForms\Http\BlueprintListings;
use UAMSWeb\MultisiteForms\Tests\TestCase;

/**
 * The blueprints index and the command palette list only the forms whose fields
 * editor the user may open, so they show no form of a site the user cannot
 * access (#133).
 *
 * @command  vendor/bin/pest tests/Integration/Access/BlueprintListingsTest.php
 */

/** The forms every test stores, by handle, with the sites each lists. */
const LISTING_FORMS = [
    'english' => ['en'],
    'shared' => ['en', 'es'],
    'spanish' => ['es'],
    'everywhere' => [],
];

beforeEach(function (): void {
    useTwoSites();
    useTemporaryFormsDirectory();
    $this->rolesFile = useTemporaryRoles();

    foreach (LISTING_FORMS as $handle => $sites) {
        $form = FormFacade::make($handle);
        if (! $form instanceof Form) {
            throw new RuntimeException('The forms repository did not make a form.');
        }

        $form->title('Title of '.$handle);
        if ($sites !== []) {
            $form->set('sites', $sites);
        }

        $form->save();
    }
});

afterEach(function (): void {
    removeFormsDirectory();
    File::delete($this->rolesFile);
});

/**
 * A user who may configure blueprints and form fields, with the given kind of site access.
 */
function blueprintsUser(string $kind): UserObject
{
    return match ($kind) {
        'super' => superUser(),
        'both' => userWith('blueprints-both', ['access cp', 'configure fields', 'configure form fields', 'access en site', 'access es site']),
        default => userWith('blueprints-es', ['access cp', 'configure fields', 'configure form fields', 'access es site']),
    };
}

/**
 * A Control Panel route's URL.
 *
 * @param  array<array-key, mixed>|string  $parameters
 */
function listingUrl(string $route, array|string $parameters = []): string
{
    $url = cp_route($route, $parameters);
    if (! is_string($url)) {
        throw new RuntimeException("The [{$route}] route did not resolve.");
    }

    return $url;
}

/**
 * The handles of the forms the blueprints index lists, sorted, and its HTML.
 *
 * @return array{list<string>, string}
 */
function indexedForms(TestCase $case): array
{
    $response = $case->get(listingUrl('blueprints.index'))->assertOk();

    $handles = [];
    $response->assertInertia(static function (AssertableInertia $page) use (&$handles): void {
        $props = $page->toArray()['props'];
        $forms = is_array($props) ? ($props[BlueprintListings::FORMS] ?? null) : null;
        foreach (is_array($forms) ? $forms : throw new RuntimeException('The page lists no forms.') as $form) {
            $handles[] = is_array($form) && is_string($form['handle'] ?? null) ? $form['handle'] : throw new RuntimeException('A form has no handle.');
        }
    });

    sort($handles);

    return [$handles, (string) $response->getContent()];
}

/**
 * The handles of the forms whose fields editor the command palette links, sorted, and its items.
 *
 * @return array{list<string>, mixed}
 */
function paletteForms(TestCase $case): array
{
    $items = $case->getJson(listingUrl('command-palette.index'))->assertOk()->json();

    $handles = [];
    foreach (is_array($items) ? $items : [] as $item) {
        foreach (array_keys(LISTING_FORMS) as $handle) {
            if (is_array($item) && ($item['url'] ?? null) === listingUrl('blueprints.forms.edit', $handle)) {
                $handles[] = $handle;
            }
        }
    }

    sort($handles);

    return [$handles, $items];
}

it('lists on the blueprints index only the forms whose editor the user may open', function (string $kind, array $handles, bool $english): void {
    $this->actingAs(blueprintsUser($kind));

    [$listed, $html] = indexedForms($this);

    // The HTML check runs both ways, so a page that stopped carrying its data would fail it
    expect($listed)->toBe($handles)
        ->and(str_contains($html, 'Title of english'))->toBe($english);
})->with([
    'an es-only user' => ['es', ['spanish'], false],
    'a user with both sites' => ['both', ['english', 'everywhere', 'shared', 'spanish'], true],
    'a super user' => ['super', ['english', 'everywhere', 'shared', 'spanish'], true],
]);

it('links from the command palette only the forms whose editor the user may open', function (string $kind, array $handles): void {
    $this->actingAs(blueprintsUser($kind));

    [$linked, $items] = paletteForms($this);

    expect($linked)->toBe($handles)
        ->and(is_array($items) && array_is_list($items))->toBeTrue();
})->with([
    'an es-only user' => ['es', ['spanish']],
    'a user with both sites' => ['both', ['english', 'everywhere', 'shared', 'spanish']],
    'a super user' => ['super', ['english', 'everywhere', 'shared', 'spanish']],
]);

it('keeps the palette items that link no fields editor', function (): void {
    $this->actingAs(blueprintsUser('es'));

    [, $items] = paletteForms($this);

    expect(array_column(is_array($items) ? $items : [], 'url'))->toContain(listingUrl('blueprints.users.edit'));
});

it('keeps an item whose link is missing or names no fields editor', function (): void {
    $this->actingAs(blueprintsUser('es'));

    expect(BlueprintListings::kept([
        ['url' => listingUrl('blueprints.forms.edit', 'english')],
        ['url' => 'http://localhost/cp/elsewhere'],
        ['text' => 'No link'],
        'not an item',
    ], 'url'))->toBe([
        ['url' => 'http://localhost/cp/elsewhere'],
        ['text' => 'No link'],
        'not an item',
    ]);
});

it('leaves every other response alone', function (?string $routeName, string $kind): void {
    $this->actingAs(blueprintsUser('es'));

    $request = Request::create('/cp/somewhere');
    if ($routeName !== null) {
        $route = new Route('GET', 'cp/somewhere', [])->name($routeName);
        $route->bind($request);
        $request->setRouteResolver(static fn (): Route => $route);
    }

    $items = [['url' => listingUrl('blueprints.forms.edit', 'english')]];
    $response = match ($kind) {
        'inertia' => Inertia::render('Page', [BlueprintListings::FORMS => 'as given']),
        'collection' => collect($items),
        default => new Response('plain'),
    };

    BlueprintListings::preparing(new PreparingResponse($request, $response));

    expect(match (true) {
        $response instanceof InertiaResponse => listingsProp($response),
        $response instanceof Collection => $response->all(),
        default => $response->getContent(),
    })->toBe(match ($kind) {
        'inertia' => 'as given',
        'collection' => $items,
        default => 'plain',
    });
})->with([
    'an Inertia page on another route' => ['statamic.cp.forms.index', 'inertia'],
    'a collection on another route' => ['statamic.cp.forms.index', 'collection'],
    'an Inertia page with no route' => [null, 'inertia'],
    'a collection with no route' => [null, 'collection'],
    'a collection on the index route' => [BlueprintListings::INDEX, 'collection'],
    'an Inertia page on the palette route' => [BlueprintListings::PALETTE, 'inertia'],
    'a plain response on the palette route' => [BlueprintListings::PALETTE, 'plain'],
]);

it('leaves a response alone whose request is not a Laravel request', function (): void {
    $this->actingAs(blueprintsUser('es'));
    $items = [['url' => listingUrl('blueprints.forms.edit', 'english')]];
    $response = collect($items);

    BlueprintListings::preparing(new PreparingResponse(SymfonyRequest::create('/cp/command-palette'), $response));

    expect($response->all())->toBe($items);
});

/**
 * The forms prop an Inertia response carries, or null.
 *
 * Read from the response's own props: inertia-laravel 2 merges shared props into
 * them at render and 3 keeps them apart, so only this prop is compared.
 */
function listingsProp(InertiaResponse $response): mixed
{
    $props = new ReflectionProperty(InertiaResponse::class, 'props')->getValue($response);

    return is_array($props) ? ($props[BlueprintListings::FORMS] ?? null) : null;
}

it('filters a palette cached under another host', function (): void {
    $this->actingAs(blueprintsUser('es'));

    // Core caches the palette per user with absolute links, built on the first host
    app()->instance('request', Request::create('https://first.test/cp/command-palette'));
    URL::forceRootUrl('https://first.test');
    CommandPalette::build();
    URL::forceRootUrl(null);

    $items = $this->getJson('http://second.test/cp/command-palette')->assertOk()->json();

    $forms = array_values(array_filter(array_column(is_array($items) ? $items : [], 'url'), static fn (mixed $url): bool => is_string($url) && str_contains($url, '/blueprints/forms/')));

    expect($forms)->toBe(['https://first.test/cp/fields/blueprints/forms/spanish/edit']);
});
