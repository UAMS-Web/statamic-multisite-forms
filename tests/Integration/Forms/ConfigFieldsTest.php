<?php

declare(strict_types=1);

use Illuminate\Http\Response;
use Illuminate\Testing\TestResponse;
use Statamic\Facades\Form as FormFacade;
use Statamic\Facades\YAML;
use Statamic\Forms\Form;
use UAMSWeb\MultisiteForms\Forms\Assignment;

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
 * @param  array<array-key, mixed>|null  $sites
 * @return array<string, mixed>
 */
function settingsPayload(?array $sites): array
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
        ->and($field['instructions'] ?? null)->toBe('The sites this form belongs to. Leave it empty to use the form on every site.')
        ->and($field['mode'] ?? null)->toBe('select');
});

it('does not add the field to any other section', function (): void {
    $props = screenProps($this->get(settingsUrl('contact'))->assertOk());

    expect(screenSection($props, 'Fields'))->not->toHaveKey('sites')
        ->and(screenSection($props, 'Sites'))->toHaveCount(1);
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
