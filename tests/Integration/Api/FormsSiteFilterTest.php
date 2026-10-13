<?php

declare(strict_types=1);

use Illuminate\Testing\TestResponse;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use Statamic\GraphQL\Queries\FormsQuery as CoreFormsQuery;
use Statamic\Http\Controllers\API\FormsController as CoreFormsController;
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\MultisiteForms\Api\FormsController;
use UAMSWeb\MultisiteForms\GraphQL\FormsQuery;
use UAMSWeb\MultisiteForms\Tests\TestCase;

/**
 * The REST and GraphQL form listings take an optional `site` filter, limiting
 * them to forms assigned to that site, forms with no sites included (#80).
 *
 * @command  vendor/bin/pest tests/Integration/Api/FormsSiteFilterTest.php
 */

/** The forms every test stores, by handle, with the sites each lists. */
const API_FORMS = [
    'english' => ['en'],
    'spanish' => ['es'],
    'shared' => ['en', 'es'],
    'everywhere' => [],
];

beforeEach(function (): void {
    useTwoSites();
    useTemporaryFormsDirectory();
    config(['statamic.api.resources.forms' => true, 'statamic.graphql.resources.forms' => true]);

    foreach (API_FORMS as $handle => $sites) {
        $form = FormFacade::make($handle);
        if (! $form instanceof Form) {
            throw new RuntimeException('The forms repository did not make a form.');
        }

        $form->title(ucfirst($handle));
        if ($sites !== []) {
            $form->set('sites', $sites);
        }

        $form->save();
    }
});

afterEach(function (): void {
    removeFormsDirectory();
});

/**
 * The handles in a list of forms from either API, sorted.
 *
 * @return list<string>
 */
function listedHandles(mixed $forms): array
{
    if (! is_array($forms)) {
        throw new RuntimeException('The response holds no list of forms.');
    }

    $handles = [];
    foreach ($forms as $form) {
        $handle = is_array($form) ? ($form['handle'] ?? null) : null;
        if (! is_string($handle)) {
            throw new RuntimeException('A listed form has no handle.');
        }

        $handles[] = $handle;
    }

    sort($handles);

    return $handles;
}

/**
 * The REST forms listing, with the given query string.
 *
 * @param  array<string, mixed>  $query
 * @return TestResponse<Response>
 */
function restForms(TestCase $case, array $query = []): TestResponse
{
    return $case->getJson('/api/forms'.($query === [] ? '' : '?'.http_build_query($query)));
}

/**
 * The GraphQL forms query, with the given arguments written as GraphQL.
 *
 * @return TestResponse<Response>
 */
function graphqlForms(TestCase $case, string $arguments = ''): TestResponse
{
    return $case->postJson('/graphql', ['query' => "{ forms{$arguments} { handle } }"]);
}

it('lists every form without a site filter', function (): void {
    expect(listedHandles(restForms($this)->assertOk()->json('data')))->toBe(['english', 'everywhere', 'shared', 'spanish']);
});

it('lists the forms assigned to the filtered site, and the forms with no sites', function (string $site, array $handles): void {
    expect(listedHandles(restForms($this, ['site' => $site])->assertOk()->json('data')))->toBe($handles);
})->with([
    'en' => ['en', ['english', 'everywhere', 'shared']],
    'es' => ['es', ['everywhere', 'shared', 'spanish']],
]);

it('refuses a site filter that names no configured site', function (mixed $site): void {
    restForms($this, ['site' => $site])
        ->assertUnprocessable()
        ->assertJson(['message' => 'The site filter names no configured site.']);
})->with([
    'an unknown handle' => ['fr'],
    'an empty value' => [''],
    'a list' => [['en']],
]);

it('filters only the forms the API exposes', function (): void {
    config(['statamic.api.resources.forms' => ['english', 'spanish']]);

    expect(listedHandles(restForms($this)->assertOk()->json('data')))->toBe(['english', 'spanish'])
        ->and(listedHandles(restForms($this, ['site' => 'en'])->assertOk()->json('data')))->toBe(['english']);
});

it('leaves the single form endpoint and a disabled API as core has them', function (): void {
    $this->getJson('/api/forms/spanish?site=en')->assertOk()->assertJsonPath('data.handle', 'spanish');

    config(['statamic.api.resources.forms' => false]);
    restForms($this, ['site' => 'en'])->assertNotFound();
});

it('lists every form from GraphQL without a site argument, or with a null one', function (string $arguments): void {
    expect(listedHandles(graphqlForms($this, $arguments)->assertOk()->json('data.forms')))->toBe(['english', 'everywhere', 'shared', 'spanish']);
})->with([
    'no argument' => [''],
    'a null site' => ['(site: null)'],
]);

it('lists the forms assigned to a site from GraphQL, and the forms with no sites', function (string $site, array $handles): void {
    expect(listedHandles(graphqlForms($this, "(site: \"{$site}\")")->assertOk()->json('data.forms')))->toBe($handles);
})->with([
    'en' => ['en', ['english', 'everywhere', 'shared']],
    'es' => ['es', ['everywhere', 'shared', 'spanish']],
]);

it('answers a GraphQL site argument that names no configured site with a validation error', function (string $site): void {
    $response = graphqlForms($this, "(site: \"{$site}\")")->assertOk();

    expect($response->json('errors.0.message'))->toBe('validation')
        ->and($response->json('errors.0.extensions.validation.site'))->toBeArray()->not->toBeEmpty()
        ->and($response->json('data.forms'))->toBeNull();
})->with([
    'an unknown handle' => ['fr'],
    'an empty value' => [''],
]);

it('filters only the forms GraphQL exposes', function (): void {
    config(['statamic.graphql.resources.forms' => ['english', 'spanish']]);

    expect(listedHandles(graphqlForms($this)->assertOk()->json('data.forms')))->toBe(['english', 'spanish'])
        ->and(listedHandles(graphqlForms($this, '(site: "es")')->assertOk()->json('data.forms')))->toBe(['spanish']);
});

it("replaces core's REST controller and GraphQL query", function (): void {
    expect(app(CoreFormsController::class))->toBeInstanceOf(FormsController::class)
        ->and(app(CoreFormsQuery::class))->toBeInstanceOf(FormsQuery::class);
});
