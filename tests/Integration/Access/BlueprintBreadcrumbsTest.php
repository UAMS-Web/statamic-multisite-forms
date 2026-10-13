<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Inertia\Testing\AssertableInertia;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\MultisiteForms\Http\BlueprintBreadcrumbs;
use UAMSWeb\MultisiteForms\Tests\TestCase;

/**
 * The form fields editor's breadcrumb links only the forms whose editor the user
 * may open, so it shows no title of a form on a site they cannot access (#124).
 *
 * @command  vendor/bin/pest tests/Integration/Access/BlueprintBreadcrumbsTest.php
 */

/** The forms every test stores, by handle, with the sites each lists. */
const BREADCRUMB_FORMS = [
    'english' => ['en'],
    'shared' => ['en', 'es'],
    'spanish' => ['es'],
    'everywhere' => [],
];

beforeEach(function (): void {
    useTwoSites();
    useTemporaryFormsDirectory();
    $this->rolesFile = useTemporaryRoles();

    foreach (BREADCRUMB_FORMS as $handle => $sites) {
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
 * The form fields editor's page for a form.
 *
 * @return TestResponse<Response>
 */
function fieldsEditorPage(TestCase $case, string $handle): TestResponse
{
    $url = cp_route('blueprints.forms.edit', $handle);
    if (! is_string($url)) {
        throw new RuntimeException('The fields editor route did not resolve.');
    }

    return $case->get($url)->assertOk();
}

/**
 * The titles the editor page's form breadcrumb links, sorted.
 *
 * @param  TestResponse<Response>  $response
 * @return list<string>
 */
function linkedTitles(TestResponse $response): array
{
    $titles = [];
    $response->assertInertia(static function (AssertableInertia $page) use (&$titles): void {
        $props = $page->toArray()['props'];
        $crumbs = is_array($props) ? ($props[BlueprintBreadcrumbs::PROP] ?? null) : null;
        if (! is_array($crumbs)) {
            throw new RuntimeException('The page has no pushed breadcrumbs.');
        }

        foreach ($crumbs as $crumb) {
            foreach (is_array($crumb) && is_array($crumb['links'] ?? null) ? $crumb['links'] : [] as $link) {
                $titles[] = is_array($link) && is_string($link['display'] ?? null) ? $link['display'] : throw new RuntimeException('A link has no title.');
            }
        }
    });

    sort($titles);

    return $titles;
}

/**
 * The breadcrumb prop an Inertia response carries, or null.
 *
 * Read from the response's own props: inertia-laravel 2 merges shared props into
 * them at render and 3 keeps them apart, so only this prop is compared.
 */
function breadcrumbProp(InertiaResponse $response): mixed
{
    $props = new ReflectionProperty(InertiaResponse::class, 'props')->getValue($response);

    return is_array($props) ? ($props[BlueprintBreadcrumbs::PROP] ?? null) : null;
}

it('links only the forms whose editor the user may open', function (): void {
    // An es-only user may open only the es-only form's editor, so no other form is linked
    $this->actingAs(userWith('fields-es', ['access cp', 'configure form fields', 'access es site']));

    $response = fieldsEditorPage($this, 'spanish');

    expect(linkedTitles($response))->toBe([])
        ->and($response->getContent())->not->toContain('Title of english');
});

it('links every other form for a user who may open every editor', function (string $kind): void {
    $this->actingAs($kind === 'super' ? superUser() : userWith('fields-both', ['access cp', 'configure form fields', 'access en site', 'access es site']));

    $response = fieldsEditorPage($this, 'spanish');

    // The positive control for the HTML assertion above: the title is in the page when allowed
    expect(linkedTitles($response))->toBe(['Title of english', 'Title of everywhere', 'Title of shared'])
        ->and($response->getContent())->toContain('Title of english');
})->with(['a super user' => ['super'], 'a user with both sites' => ['both']]);

it('filters the breadcrumb on an Inertia visit as on a full page load', function (): void {
    $this->actingAs(userWith('fields-es', ['access cp', 'configure form fields', 'access es site']));
    $url = cp_route('blueprints.forms.edit', 'spanish');
    if (! is_string($url)) {
        throw new RuntimeException('The fields editor route did not resolve.');
    }

    $version = Inertia::getVersion();
    $crumbs = $this->get($url, ['X-Inertia' => 'true', 'X-Inertia-Version' => $version])->assertOk()->json('props.'.BlueprintBreadcrumbs::PROP);

    expect($crumbs)->toBeArray()
        ->and(json_encode($crumbs))->not->toContain('Title of english')
        ->and(json_encode($crumbs))->toContain('Title of spanish');
});

it('keeps the current form and the forms crumb, and every link that names no fields editor', function (): void {
    $this->actingAs(userWith('fields-es', ['access cp', 'configure form fields', 'access es site']));

    $crumbs = BlueprintBreadcrumbs::openable([
        ['display' => 'Forms', 'links' => []],
        ['display' => 'Title of spanish', 'links' => [
            ['display' => 'Title of english', 'url' => cp_route('blueprints.forms.edit', 'english')],
            ['display' => 'Elsewhere', 'url' => 'http://localhost/cp/elsewhere'],
            'not a link',
        ]],
        'not a crumb',
        ['display' => 'No links'],
    ]);

    expect($crumbs)->toBe([
        ['display' => 'Forms', 'links' => []],
        ['display' => 'Title of spanish', 'links' => [
            ['display' => 'Elsewhere', 'url' => 'http://localhost/cp/elsewhere'],
            'not a link',
        ]],
        'not a crumb',
        ['display' => 'No links'],
    ]);
});

it('leaves every other response alone', function (?string $routeName, bool $inertia): void {
    $this->actingAs(userWith('fields-es', ['access cp', 'configure form fields', 'access es site']));

    $request = Request::create('/cp/somewhere');
    if ($routeName !== null) {
        $route = new Route('GET', 'cp/somewhere', [])->name($routeName);
        $route->bind($request);
        $request->setRouteResolver(static fn (): Route => $route);
    }

    $response = $inertia ? Inertia::render('Page', [BlueprintBreadcrumbs::PROP => 'as given']) : new Response('plain');
    BlueprintBreadcrumbs::preparing(new PreparingResponse($request, $response));

    expect($response instanceof InertiaResponse ? breadcrumbProp($response) : $response->getContent())->toBe($inertia ? 'as given' : 'plain');
})->with([
    'an Inertia page on another route' => ['statamic.cp.forms.edit', true],
    'an Inertia page with no route' => [null, true],
    'a plain response on the editor route' => [BlueprintBreadcrumbs::ROUTE, false],
]);

it('leaves a response alone whose request is not a Laravel request', function (): void {
    $response = Inertia::render('Page', [BlueprintBreadcrumbs::PROP => 'as given']);
    BlueprintBreadcrumbs::preparing(new PreparingResponse(SymfonyRequest::create('/cp/fields/blueprints/forms/spanish/edit'), $response));

    expect(breadcrumbProp($response))->toBe('as given');
});

it('listens for responses being prepared', function (): void {
    $request = Request::create('/cp/fields/blueprints/forms/spanish/edit');
    $route = new Route('GET', 'cp/fields/blueprints/forms/{form}/edit', [])->name(BlueprintBreadcrumbs::ROUTE);
    $route->bind($request);

    $request->setRouteResolver(static fn (): Route => $route);
    $this->actingAs(superUser());

    $response = Inertia::render('Page');
    event(new PreparingResponse($request, $response));

    // Nothing was pushed, so the filtered list is empty rather than absent
    expect(breadcrumbProp($response))->toBe([]);
});
