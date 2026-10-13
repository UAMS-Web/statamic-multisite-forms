<?php

declare(strict_types=1);

use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Statamic\Facades\Form as FormFacade;
use Statamic\Facades\YAML;
use Statamic\Fields\Blueprint;
use Statamic\Fields\Field;
use Statamic\Fields\Value;
use Statamic\Forms\Form;
use Statamic\Forms\Tags;
use Statamic\Http\Controllers\CP\Forms\FormsController;
use Statamic\Sites\Site as SiteObject;
use Statamic\Tags\Loader;
use UAMSWeb\MultisiteForms\Forms\Assignment;
use UAMSWeb\MultisiteForms\Forms\ConfigFields;
use UAMSWeb\MultisiteForms\Forms\FormResolver;
use UAMSWeb\MultisiteForms\Forms\Overrides;

/**
 * The form settings screen gains a Sites section whose selection is saved to the
 * form file as `sites`, through core's own edit and update endpoints (#70).
 *
 * @command  vendor/bin/pest tests/Integration/Forms/ConfigFieldsTest.php
 */
beforeEach(function (): void {
    useTwoSites();
    useTemporaryFormsDirectory();
    $this->actingAs(superUser());

    $form = FormFacade::make('contact');
    $form->title('Contact');
    $form->save();
});

afterEach(function (): void {
    removeFormsDirectory();
});

/**
 * The settings a core form screen submits, with `sites` set when given.
 *
 * @param  array<array-key, mixed>|string|null  $sites
 * @return array<string, mixed>
 */
function settingsPayload(array|string|null $sites): array
{
    $payload = [
        'title' => 'Contact',
        'honeypot' => 'trap',
        'store' => false,
        'email' => [['to' => 'base@example.com', 'subject' => 'Base']],
    ];

    if ($sites !== null) {
        $payload['sites'] = $sites;
    }

    return $payload;
}

/**
 * The URL of a form's settings screen, or of the endpoint it saves to.
 *
 * @throws RuntimeException When the route does not resolve to a URL.
 */
function settingsUrl(string $handle, string $route = 'forms.edit'): string
{
    $url = cp_route($route, $handle);
    if (! is_string($url)) {
        throw new RuntimeException("The [{$route}] route did not resolve.");
    }

    return $url;
}

/**
 * The settings payload with override rows, as the screen submits them: each
 * row's site a one-item list.
 *
 * @param  array<array-key, mixed>|string|null  $sites
 * @param  list<array<string, mixed>>  $rows
 * @return array<string, mixed>
 */
function overridesPayload(array|string|null $sites, array $rows): array
{
    return [...settingsPayload($sites), Overrides::KEY => $rows];
}

/**
 * A stored form, read back through core's repository.
 *
 * @throws RuntimeException When no form with that handle is stored.
 */
function storedForm(string $handle): Form
{
    $form = FormFacade::find($handle);
    if (! $form instanceof Form) {
        throw new RuntimeException("No [{$handle}] form is stored.");
    }

    return $form;
}

/**
 * The parsed form file for a handle.
 *
 * @return array<string, mixed>
 */
function formFile(string $handle): array
{
    /** @var array<string, mixed> */
    return YAML::file(formsDirectory()."/{$handle}.yaml")->parse();
}

/**
 * The Inertia page props of a settings screen response.
 *
 * @param  TestResponse<Response>  $response
 * @return array<string, mixed>
 */
function screenProps(TestResponse $response): array
{
    /** @var array{props: array<string, mixed>} $page */
    $page = $response->viewData('page');

    return $page['props'];
}

/**
 * The fields of one section of a settings screen, keyed by handle.
 *
 * @param  array<string, mixed>  $props
 * @return array<string, array<string, mixed>>
 */
function screenSection(array $props, string $display): array
{
    /** @var array{tabs: list<array{sections: list<array{display: string, fields: list<array<string, mixed>>}>}>} $blueprint */
    $blueprint = $props['blueprint'];

    $fields = [];
    foreach ($blueprint['tabs'] as $tab) {
        foreach ($tab['sections'] as $section) {
            if ($section['display'] !== $display) {
                continue;
            }

            foreach ($section['fields'] as $field) {
                if (is_string($field['handle'] ?? null)) {
                    $fields[$field['handle']] = $field;
                }
            }
        }
    }

    return $fields;
}

it('adds a Sites section holding a sites field to the form settings screen', function (): void {
    $response = $this->get(settingsUrl('contact'))->assertOk();

    $fields = screenSection(screenProps($response), 'Sites');

    expect($fields)->toHaveKey('sites')
        ->and($fields['sites']['type'] ?? null)->toBe('sites');
});

it('labels the field, explains an empty selection, and offers the sites as a select', function (): void {
    $field = screenSection(screenProps($this->get(settingsUrl('contact'))->assertOk()), 'Sites')['sites'] ?? [];

    expect($field['display'] ?? null)->toBe('Sites')
        ->and($field['instructions'] ?? null)->toBe('The sites this form belongs to. Leave it empty to use the form on every site, which only an editor who can access every site may do.')
        ->and($field['mode'] ?? null)->toBe('select');
});

it('does not add the field to any other section', function (): void {
    $props = screenProps($this->get(settingsUrl('contact'))->assertOk());

    expect(screenSection($props, 'Fields'))->not->toHaveKey('sites')
        ->and(screenSection($props, 'Sites'))->toHaveCount(2);
});

it('adds the section to every form, not only one handle', function (): void {
    $config = FormFacade::extraConfigFor('any_form');

    expect($config)->toHaveKey('sites')
        ->and(data_get($config, 'sites.fields'))->toHaveKey('sites');
});

it('saves the selected sites to the form file and shows them on the screen again', function (array $sites): void {
    $this->patchJson(settingsUrl('contact', 'forms.update'), settingsPayload($sites))->assertOk();

    expect(formFile('contact')['sites'] ?? null)->toBe($sites)
        ->and(Assignment::sites(storedForm('contact')))->toBe($sites);

    /** @var array{sites?: mixed} $values */
    $values = screenProps($this->get(settingsUrl('contact'))->assertOk())['values'];

    expect($values['sites'] ?? null)->toBe($sites);
})->with([
    'one site' => [['es']],
    'both sites' => [['en', 'es']],
]);

it('leaves the sites key out of the file when no site is selected, so the form is global', function (?array $sites): void {
    $this->patchJson(settingsUrl('contact', 'forms.update'), settingsPayload(['es']))->assertOk();
    expect(formFile('contact')['sites'] ?? null)->toBe(['es']);

    $this->patchJson(settingsUrl('contact', 'forms.update'), settingsPayload($sites))->assertOk();

    expect(formFile('contact'))->not->toHaveKey('sites')
        ->and(Assignment::isGlobal(storedForm('contact')))->toBeTrue();
})->with([
    'an empty selection' => [[]],
    'no sites value sent' => [null],
]);

it('refuses a selection naming a site the install does not define, and leaves the file alone', function (array|string $sites): void {
    $this->patchJson(settingsUrl('contact', 'forms.update'), settingsPayload(['es']))->assertOk();

    $this->patchJson(settingsUrl('contact', 'forms.update'), settingsPayload($sites))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('sites');

    expect(formFile('contact')['sites'] ?? null)->toBe(['es']);
})->with([
    'an unknown handle' => [['nope']],
    'a known handle beside an unknown one' => [['es', 'nope']],
    'a handle that is not a string' => [[['es']]],
    'a bare handle rather than a list' => ['nope'],
]);

it('renders the form tag of an assigned form, exposing its sites to the template', function (): void {
    $this->patchJson(settingsUrl('contact', 'forms.update'), settingsPayload(['en', 'es']))->assertOk();

    // With no content, core's tag returns the data a template would receive.
    $tag = app(Loader::class)->load('form', [
        'parser' => null,
        'params' => ['in' => 'contact'],
        'content' => '',
        'context' => [],
        'tag' => 'form:create',
        'tag_method' => 'create',
    ]);
    if (! $tag instanceof Tags) {
        throw new RuntimeException('The form tag did not load.');
    }

    $sites = data_get($tag->create(), 'form_config.sites');
    $sites = $sites instanceof Value ? $sites->value() : $sites;
    if (! is_iterable($sites)) {
        throw new RuntimeException('The form tag exposed no sites.');
    }

    $handles = [];
    foreach ($sites as $site) {
        $handles[] = $site instanceof SiteObject ? $site->handle() : $site;
    }

    expect($handles)->toBe(['en', 'es']);
});

it('writes core keys exactly as core writes them, adding only the sites key', function (): void {
    $global = FormFacade::make('global_contact');
    $global->title('Contact');
    $global->save();

    $this->patchJson(settingsUrl('contact', 'forms.update'), settingsPayload(['es']))->assertOk();
    $this->patchJson(settingsUrl('global_contact', 'forms.update'), settingsPayload([]))->assertOk();

    $assigned = formFile('contact');
    $unassigned = formFile('global_contact');

    expect(array_diff_key($assigned, $unassigned))->toBe(['sites' => ['es']])
        ->and(array_diff_key($assigned, ['sites' => true]))->toBe($unassigned)
        ->and($unassigned)->toBe([
            'title' => 'Contact',
            'honeypot' => 'trap',
            'email' => [['to' => 'base@example.com', 'subject' => 'Base']],
            'store' => false,
        ]);
});

it('adds a site overrides grid to the Sites section (#76)', function (): void {
    $field = screenSection(screenProps($this->get(settingsUrl('contact'))->assertOk()), 'Sites')[Overrides::KEY] ?? [];

    /** @var list<array{handle: string}> $columns */
    $columns = $field['fields'] ?? [];

    expect($field['type'] ?? null)->toBe('grid')
        ->and($field['display'] ?? null)->toBe('Site overrides')
        ->and(array_column($columns, 'handle'))->toBe(['site', 'title', 'store', 'email']);
});

it('saves override rows to the form file and shows them on the screen again (#76)', function (): void {
    $es = [['to' => 'es@example.com', 'subject' => 'ES']];

    $this->patchJson(settingsUrl('contact', 'forms.update'), overridesPayload(['en', 'es'], [
        ['site' => ['es'], 'title' => 'Contacto', 'store' => Overrides::DO_NOT_STORE, 'email' => $es],
    ]))->assertOk();

    /** @var list<array<string, mixed>> $rows */
    $rows = formFile('contact')[Overrides::KEY] ?? [];

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['site'] ?? null)->toBe('es')
        ->and($rows[0]['title'] ?? null)->toBe('Contacto')
        ->and($rows[0]['store'] ?? null)->toBe(Overrides::DO_NOT_STORE)
        ->and(data_get($rows, '0.email.0.to'))->toBe('es@example.com')
        ->and(data_get($rows, '0.email.0.subject'))->toBe('ES');

    /** @var array<string, mixed> $values */
    $values = screenProps($this->get(settingsUrl('contact'))->assertOk())['values'];

    expect(data_get($values, Overrides::KEY.'.0.title'))->toBe('Contacto')
        ->and(data_get($values, Overrides::KEY.'.0.store'))->toBe(Overrides::DO_NOT_STORE)
        ->and(data_get($values, Overrides::KEY.'.0.email.0.to'))->toBe('es@example.com');
});

it('resolves a saved row for its site, through the form the editor saved (#76)', function (): void {
    $this->patchJson(settingsUrl('contact', 'forms.update'), overridesPayload(['en', 'es'], [
        ['site' => ['es'], 'title' => 'Contacto', 'store' => Overrides::STORE],
    ]))->assertOk();

    $resolver = new FormResolver;
    $form = storedForm('contact');

    expect($resolver->resolve($form, 'es')?->title())->toBe('Contacto')
        ->and($resolver->resolve($form, 'es')?->store())->toBeTrue()
        ->and($resolver->resolve($form, 'en')?->title())->toBe('Contact')
        ->and($resolver->resolve($form, 'en')?->store())->toBeFalse();
});

it('leaves store out of a row whose store is left unset, so the form keeps its own (#76)', function (): void {
    $this->patchJson(settingsUrl('contact', 'forms.update'), overridesPayload(['en', 'es'], [
        ['site' => ['es'], 'title' => 'Contacto', 'store' => null],
    ]))->assertOk();

    /** @var list<array<string, mixed>> $rows */
    $rows = formFile('contact')[Overrides::KEY] ?? [];

    expect($rows[0] ?? [])->not->toHaveKey('store');
});

it('refuses a second row for the same site, naming the site, and leaves the file alone (#76)', function (): void {
    $this->patchJson(settingsUrl('contact', 'forms.update'), settingsPayload(['es']))->assertOk();
    $before = formFile('contact');

    $this->patchJson(settingsUrl('contact', 'forms.update'), overridesPayload(['en', 'es'], [
        ['site' => ['es'], 'title' => 'Uno'],
        ['site' => ['es'], 'title' => 'Dos'],
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([Overrides::KEY => 'Spanish has more than one override row. Keep one row per site.']);

    expect(formFile('contact'))->toBe($before);
});

it('refuses a row for a site the form is not assigned to, naming the site (#76)', function (): void {
    $this->patchJson(settingsUrl('contact', 'forms.update'), overridesPayload(['en'], [
        ['site' => ['es'], 'title' => 'Contacto'],
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([Overrides::KEY => "Spanish has an override row but is not one of this form's sites."]);

    expect(formFile('contact'))->not->toHaveKey(Overrides::KEY);
});

it('refuses a row naming a site the install does not define (#76)', function (): void {
    $this->patchJson(settingsUrl('contact', 'forms.update'), overridesPayload(['en', 'es'], [
        ['site' => ['nope'], 'title' => 'Nope'],
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(Overrides::KEY);
});

it('accepts a row for any site on a form with no sites (#76)', function (string $site): void {
    $this->patchJson(settingsUrl('contact', 'forms.update'), overridesPayload([], [
        ['site' => [$site], 'title' => 'Override'],
    ]))->assertOk();

    expect(data_get(formFile('contact'), Overrides::KEY.'.0.site'))->toBe($site);
})->with(['en', 'es']);

it('refuses a row with no site (#76)', function (): void {
    $this->patchJson(settingsUrl('contact', 'forms.update'), overridesPayload(['en', 'es'], [
        ['site' => [], 'title' => 'Nowhere'],
    ]))->assertUnprocessable();

    expect(formFile('contact'))->not->toHaveKey(Overrides::KEY);
});

it('gives an override email the same columns as core gives a form email (#76)', function (?string $sender): void {
    config(['mail.from.address' => $sender]);

    // Core builds its email columns inside a protected method, so they are read the way the screen reads them
    $blueprint = new ReflectionMethod(FormsController::class, 'editFormBlueprint')
        ->invoke(app(FormsController::class), storedForm('contact'));
    if (! $blueprint instanceof Blueprint) {
        throw new RuntimeException('Core built no settings blueprint.');
    }

    $email = $blueprint->field('email');
    if (! $email instanceof Field) {
        throw new RuntimeException('Core built no email field.');
    }

    expect(ConfigFields::emailFields())->toEqual($email->config()['fields'] ?? null);
})->with([
    'a sender address' => ['hello@example.com'],
    'no sender address' => [null],
]);

it('configures the override grid and its columns for the screen (#76)', function (): void {
    $field = screenSection(screenProps($this->get(settingsUrl('contact'))->assertOk()), 'Sites')[Overrides::KEY] ?? [];

    /** @var list<array<string, mixed>> $list */
    $list = $field['fields'] ?? [];
    $columns = array_column($list, null, 'handle');

    expect($field)->toMatchArray([
        'mode' => 'stacked',
        'add_row' => 'Add site override',
        'instructions' => "Change the title, storing or email for one site. Anything a row leaves empty uses the form's own setting.",
    ])
        ->and($columns['site'] ?? [])->toMatchArray(['type' => 'sites', 'mode' => 'select', 'max_items' => 1, 'required' => true])
        ->and($columns['title'] ?? [])->toMatchArray(['type' => 'text', 'instructions' => "Leave empty to use the form's title."])
        ->and($columns['store'] ?? [])->toMatchArray([
            'display' => 'Store Submissions',
            'type' => 'button_group',
            'clearable' => true,
            'instructions' => "Leave unset to use the form's setting.",
        ])
        ->and($columns['email'] ?? [])->toMatchArray([
            'type' => 'grid',
            'mode' => 'stacked',
            'add_row' => 'Add Email',
            'instructions' => "Replaces the form's emails for this site. Leave empty to send the form's emails.",
        ]);

    /** @var list<array{handle: string}> $emailColumns */
    $emailColumns = $columns['email']['fields'] ?? [];

    expect(array_column($emailColumns, 'handle'))->toBe(array_column(ConfigFields::emailFields(), 'handle'));

    /** @var list<array{value: string, label: string}>|array<string, string> $options */
    $options = $columns['store']['options'] ?? [];

    expect(json_encode($options))->toContain('"true"')
        ->toContain('"Store"')
        ->toContain('"false"')
        ->toContain("Don't store");
});
