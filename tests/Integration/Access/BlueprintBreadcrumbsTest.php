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
function fieldsEditorPage(string $handle): TestResponse
{
    $url = cp_route('blueprints.forms.edit', $handle);
    if (! is_string($url)) {
        throw new RuntimeException('The fields editor route did not resolve.');
    }

    return test()->get($url)->assertOk();
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
        $crumbs = $page->toArray()['props'][BlueprintBreadcrumbs::PROP] ?? null;
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

it('links only the forms whose editor the user may open', function (array $sites, string $handle, array $titles): void {
    $this->actingAs(userWith('fields-'.implode('-', $sites), ['access cp', 'configure form fields', ...array_map(static fn (string $site): string => "access {$site} site", $sites)]));

    $response = fieldsEditorPage($handle);

    expect(linkedTitles($response))->toBe($titles)
        ->and($response->getContent())->not->toContain('Title of english');
})->with([
    'an es-only user, es-only form' => [['es'], 'spanish', []],
]);

it('links every other form for a user who may open every editor', function (string $kind): void {
    $this->actingAs($kind === 'super' ? superUser() : userWith('fields-both', ['access cp', 'configure form fields', 'access en site', 'access es site']));

    $response = fieldsEditorPage('spanish');

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

    $response = $inertia ? new InertiaResponse('Page', [], ['additionalBreadcrumbs' => 'as given'], 'app') : new Response('plain');
    BlueprintBreadcrumbs::preparing(new PreparingResponse($request, $response));

    $props = $inertia ? new ReflectionProperty(InertiaResponse::class, 'props')->getValue($response) : null;
    expect($props)->toBe($inertia ? ['additionalBreadcrumbs' => 'as given'] : null);
})->with([
    'an Inertia page on another route' => ['statamic.cp.forms.edit', true],
    'an Inertia page with no route' => [null, true],
    'a plain response on the editor route' => [BlueprintBreadcrumbs::ROUTE, false],
]);

it('leaves a response alone whose request is not a Laravel request', function (): void {
    $response = new InertiaResponse('Page', [], ['additionalBreadcrumbs' => 'as given'], 'app');
    BlueprintBreadcrumbs::preparing(new PreparingResponse(SymfonyRequest::create('/cp/fields/blueprints/forms/spanish/edit'), $response));

    expect(new ReflectionProperty(InertiaResponse::class, 'props')->getValue($response))->toBe(['additionalBreadcrumbs' => 'as given']);
});

it('listens for responses being prepared', function (): void {
    $request = Request::create('/cp/fields/blueprints/forms/spanish/edit');
    $route = new Route('GET', 'cp/fields/blueprints/forms/{form}/edit', [])->name(BlueprintBreadcrumbs::ROUTE);
    $route->bind($request);

    $request->setRouteResolver(static fn (): Route => $route);
    $this->actingAs(superUser());

    $response = new InertiaResponse('Page', [], [], 'app');
    event(new PreparingResponse($request, $response));

    expect(new ReflectionProperty(InertiaResponse::class, 'props')->getValue($response))->toHaveKey(BlueprintBreadcrumbs::PROP);
});
