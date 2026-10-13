<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Statamic\Actions\DuplicateForm as CoreDuplicateForm;
use Statamic\Auth\File\User as UserObject;
use Statamic\Facades\Action;
use Statamic\Facades\Form as FormFacade;
use Statamic\Fields\BlueprintRepository;
use Statamic\Forms\Form;
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\MultisiteForms\Actions\DuplicateForm;
use UAMSWeb\MultisiteForms\Tests\TestCase;

/**
 * Core's Duplicate action on forms is refused for a form the user may not view
 * under the site rules, and still copies one they may (#117).
 *
 * @command  vendor/bin/pest tests/Integration/Access/DuplicateFormTest.php
 */

/** The forms every test stores, by handle, with the sites each lists. */
const DUPLICATE_FORMS = [
    'english' => ['en'],
    'shared' => ['en', 'es'],
    'spanish' => ['es'],
];

beforeEach(function (): void {
    useTwoSites();
    useTemporaryFormsDirectory();
    $this->rolesFile = useTemporaryRoles();

    // The action writes the copy's blueprint, so blueprints go to a directory of this test's own
    $this->blueprintsDirectory = sys_get_temp_dir().'/msf-blueprints-'.uniqid('', true);
    app(BlueprintRepository::class)->setDirectories($this->blueprintsDirectory);

    foreach (DUPLICATE_FORMS as $handle => $sites) {
        $form = FormFacade::make($handle);
        if (! $form instanceof Form) {
            throw new RuntimeException('The forms repository did not make a form.');
        }

        $form->title(ucfirst($handle));
        $form->email([['to' => "{$handle}@example.com"]]);
        $form->set('sites', $sites);
        $form->save();
    }
});

afterEach(function (): void {
    removeFormsDirectory();
    File::delete($this->rolesFile);
    File::deleteDirectory($this->blueprintsDirectory);
});

/**
 * A user with `configure forms` who can access the given sites.
 *
 * @param  list<string>  $sites
 */
function formsConfigurer(string $id, array $sites): UserObject
{
    return userWith($id, ['access cp', 'configure forms', ...array_map(static fn (string $site): string => "access {$site} site", $sites)]);
}

/**
 * A Control Panel action route's URL.
 *
 * @throws RuntimeException When the route does not resolve to a URL.
 */
function actionUrl(string $route): string
{
    $url = cp_route($route);
    if (! is_string($url)) {
        throw new RuntimeException("The [{$route}] route did not resolve.");
    }

    return $url;
}

/**
 * Run the Duplicate action on a form, copying it to the handle `copy`.
 *
 * @return TestResponse<Response>
 */
function duplicate(TestCase $case, string ...$handles): TestResponse
{
    return $case->postJson(actionUrl('forms.actions.run'), [
        'action' => 'duplicate_form',
        'selections' => $handles,
        'values' => ['title' => 'Copy', 'handle' => 'copy'],
    ]);
}

/**
 * The handles of the actions the Control Panel offers for the given forms.
 *
 * @return list<mixed>
 *
 * @throws RuntimeException When the response is not a list of actions.
 */
function offeredActions(TestCase $case, string ...$handles): array
{
    $actions = $case->postJson(actionUrl('forms.actions.bulk'), ['selections' => $handles])->assertOk()->json();
    if (! is_array($actions)) {
        throw new RuntimeException('The action list is not an array.');
    }

    return array_values(array_map(static fn (mixed $action): mixed => is_array($action) ? ($action['handle'] ?? null) : null, $actions));
}

/**
 * The handles of every stored form.
 *
 * @return list<mixed>
 */
function storedHandles(): array
{
    return array_values(FormFacade::all()->map(static fn (mixed $form): mixed => $form instanceof Form ? $form->handle() : null)->all());
}

/**
 * The form the Duplicate action stored.
 *
 * @throws RuntimeException When no copy was stored.
 */
function storedCopy(): Form
{
    $copy = FormFacade::find('copy');
    if (! $copy instanceof Form) {
        throw new RuntimeException('No copy was stored.');
    }

    return $copy;
}

test('the duplicate is refused for a form of a site the user cannot access', function (): void {
    $this->actingAs(formsConfigurer('es-configurer', ['es']));

    duplicate($this, 'english')->assertForbidden();

    expect(storedHandles())->not->toContain('copy');
});

test('the duplicate is refused when one of several forms is of another site', function (): void {
    $this->actingAs(formsConfigurer('es-configurer', ['es']));

    duplicate($this, 'spanish', 'english')->assertForbidden();

    expect(storedHandles())->not->toContain('copy');
});

test('a form the user can view is duplicated with its sites', function (string $id, string $sites, string $source): void {
    $this->actingAs(formsConfigurer($id, explode(',', $sites)));

    duplicate($this, $source)->assertOk();

    $copy = storedCopy();
    expect($copy->get('sites'))->toBe(DUPLICATE_FORMS[$source])
        ->and($copy->email())->toBe([['to' => "{$source}@example.com"]])
        ->and(File::exists($this->blueprintsDirectory.'/forms/copy.yaml'))->toBeTrue();
})->with([
    'es user, es form' => ['es-configurer', 'es', 'spanish'],
    'es user, shared form' => ['es-configurer', 'es', 'shared'],
    'both sites, en form' => ['both-configurer', 'en,es', 'english'],
]);

test('a super user duplicates a form of any site', function (): void {
    $this->actingAs(superUser());

    duplicate($this, 'english')->assertOk();

    expect(storedCopy()->get('sites'))->toBe(['en']);
});

test('the action is not offered for a form of a site the user cannot access', function (): void {
    $this->actingAs(formsConfigurer('es-configurer', ['es']));

    expect(offeredActions($this, 'english'))->not->toContain('duplicate_form')
        ->and(offeredActions($this, 'spanish', 'english'))->not->toContain('duplicate_form')
        ->and(offeredActions($this, 'spanish'))->toContain('duplicate_form')
        ->and(offeredActions($this, 'spanish', 'shared'))->toContain('duplicate_form');
});

test('the add-on action replaces the core action under the same handle', function (): void {
    expect(Action::get('duplicate_form'))->toBeInstanceOf(DuplicateForm::class)
        ->and(DuplicateForm::handle())->toBe(CoreDuplicateForm::handle())
        ->and(Action::all()->filter(static fn (mixed $action): bool => $action instanceof CoreDuplicateForm))->toHaveCount(1);
});

test('the action is refused for a selection that names no form', function (): void {
    $user = formsConfigurer('es-configurer', ['es']);

    expect(app(DuplicateForm::class)->authorize($user, null))->toBeFalse()
        ->and(app(DuplicateForm::class)->authorize($user, FormFacade::find('spanish')))->toBeTrue();
});

test('a user who cannot create forms is refused even a form they can view', function (): void {
    $user = userWith('es-viewer', ['access cp', 'access es site', 'view spanish form submissions']);

    expect(app(DuplicateForm::class)->authorize($user, FormFacade::find('spanish')))->toBeFalse();
});
