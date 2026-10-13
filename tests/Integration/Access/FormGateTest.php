<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Statamic\Auth\File\User as UserObject;
use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use UAMSWeb\MultisiteForms\Access\FormGate;

/**
 * Control Panel users are denied the forms of sites they cannot access, and
 * changing a form needs every site it reaches; `configure forms` does not bypass
 * either rule, only a super user does (#77).
 *
 * @command  vendor/bin/pest tests/Integration/Access/FormGateTest.php
 */

/** The forms every test stores, by handle, with the sites each lists. */
const GATE_FORMS = [
    'english' => ['en'],
    'shared' => ['en', 'es'],
    'everywhere' => [],
    'removed' => ['fr'],
];

beforeEach(function (): void {
    useTwoSites();
    useTemporaryFormsDirectory();
    $this->rolesFile = useTemporaryRoles();

    foreach (GATE_FORMS as $handle => $sites) {
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
    File::delete($this->rolesFile);
});

/**
 * The permission to view every stored form's submissions.
 *
 * @return list<string>
 */
function viewEveryForm(): array
{
    return array_map(static fn (string $handle): string => "view {$handle} form submissions", array_keys(GATE_FORMS));
}

/**
 * A user of one of the kinds the tests compare, by name.
 *
 * @throws RuntimeException When the name is not one of the kinds.
 */
function gateUser(string $kind): UserObject
{
    $cp = ['access cp'];
    $es = ['access es site'];
    $both = ['access en site', 'access es site'];

    return match ($kind) {
        'super' => superUser(),
        'es configurer' => userWith('es-configurer', [...$cp, ...$es, 'configure forms', 'configure form fields']),
        'both configurer' => userWith('both-configurer', [...$cp, ...$both, 'configure forms', 'configure form fields']),
        'es viewer' => userWith('es-viewer', [...$cp, ...$es, ...viewEveryForm()]),
        'both viewer' => userWith('both-viewer', [...$cp, ...$both, ...viewEveryForm()]),
        default => throw new RuntimeException("No [{$kind}] user."),
    };
}

/**
 * A stored form by handle.
 *
 * @throws RuntimeException When no form with that handle is stored.
 */
function gateForm(string $handle): Form
{
    $form = FormFacade::find($handle);
    if (! $form instanceof Form) {
        throw new RuntimeException("No [{$handle}] form is stored.");
    }

    return $form;
}

/**
 * The URL of a Control Panel route, for a form when given.
 *
 * @throws RuntimeException When the route does not resolve to a URL.
 */
function gateUrl(string $route, ?string $handle = null): string
{
    $url = $handle === null ? cp_route($route) : cp_route($route, $handle);
    if (! is_string($url)) {
        throw new RuntimeException("The [{$route}] route did not resolve.");
    }

    return $url;
}

/**
 * Whether the gate allows a user an ability on a form.
 */
function gateAllows(string $kind, string $ability, string $handle): bool
{
    return Gate::forUser(gateUser($kind))->allows($ability, gateForm($handle));
}

it('decides view and edit by the user and the sites the form lists', function (string $kind, string $handle, bool $view, bool $edit): void {
    expect(gateAllows($kind, 'view', $handle))->toBe($view)
        ->and(gateAllows($kind, 'edit', $handle))->toBe($edit);
})->with([
    // A super user passes every rule, even for a site no longer configured
    'super, en-only form' => ['super', 'english', true, true],
    'super, en and es form' => ['super', 'shared', true, true],
    'super, global form' => ['super', 'everywhere', true, true],
    'super, form of a removed site' => ['super', 'removed', true, true],

    // configure forms no longer bypasses: edit needs every site the form reaches
    'es configurer, en-only form' => ['es configurer', 'english', false, false],
    'es configurer, en and es form' => ['es configurer', 'shared', true, false],
    'es configurer, global form' => ['es configurer', 'everywhere', true, false],
    'es configurer, form of a removed site' => ['es configurer', 'removed', false, false],
    'both configurer, en-only form' => ['both configurer', 'english', true, true],
    'both configurer, en and es form' => ['both configurer', 'shared', true, true],
    'both configurer, global form' => ['both configurer', 'everywhere', true, true],
    'both configurer, form of a removed site' => ['both configurer', 'removed', false, false],

    // A plain viewer keeps core's answer for every form it can reach
    'es viewer, en-only form' => ['es viewer', 'english', false, false],
    'es viewer, en and es form' => ['es viewer', 'shared', true, false],
    'es viewer, global form' => ['es viewer', 'everywhere', true, false],
    'both viewer, en-only form' => ['both viewer', 'english', true, false],
    'both viewer, en and es form' => ['both viewer', 'shared', true, false],
    'both viewer, global form' => ['both viewer', 'everywhere', true, false],
]);

it('treats deleting a form and editing its fields as changing it', function (string $ability): void {
    expect(gateAllows('es configurer', $ability, 'shared'))->toBeFalse()
        ->and(gateAllows('es configurer', $ability, 'everywhere'))->toBeFalse()
        ->and(gateAllows('both configurer', $ability, 'shared'))->toBeTrue()
        ->and(gateAllows('both configurer', $ability, 'everywhere'))->toBeTrue();
})->with(['delete', 'configure form fields']);

it('applies the site rule to a submission through its form', function (): void {
    $english = gateForm('english')->makeSubmission();
    $shared = gateForm('shared')->makeSubmission();
    $user = Gate::forUser(gateUser('es configurer'));

    expect($user->allows('view', $english))->toBeFalse()
        ->and($user->allows('delete', $english))->toBeFalse()
        ->and($user->allows('view', $shared))->toBeTrue()
        ->and($user->allows('delete', $shared))->toBeTrue();
});

it('leaves abilities asked of the form class to core', function (string $kind): void {
    $user = Gate::forUser(gateUser($kind));

    expect($user->allows('create', FormContract::class))->toBeTrue()
        ->and($user->allows('index', FormContract::class))->toBeTrue();
})->with(['es configurer', 'both configurer']);

it('leaves every form to core on a single-site install', function (): void {
    config(['statamic.system.multisite' => false]);

    expect(gateAllows('es configurer', 'edit', 'english'))->toBeTrue()
        ->and(gateAllows('es configurer', 'edit', 'everywhere'))->toBeTrue();
});

it('returns null for a subject that is neither a form nor a submission', function (): void {
    expect(FormGate::decide(gateUser('es viewer'), 'view', [configuredSite('en')]))->toBeNull()
        ->and(FormGate::decide(gateUser('es viewer'), 'view', []))->toBeNull()
        ->and(FormGate::decide(gateUser('es viewer'), 'view', ['english']))->toBeNull();
});

it('never grants: it returns null where it does not deny', function (): void {
    expect(FormGate::decide(gateUser('both configurer'), 'edit', [gateForm('shared')]))->toBeNull()
        ->and(FormGate::decide(superUser(), 'edit', [gateForm('removed')]))->toBeNull()
        ->and(FormGate::decide(gateUser('es configurer'), 'edit', [gateForm('shared')]))->toBeFalse();
});

it('does not list an en-only form on the forms index for an es-only user', function (): void {
    $this->actingAs(gateUser('es viewer'));

    /** @var array{props: array{forms: list<array{id: string, can_edit: bool}>}} $page */
    $page = $this->get(gateUrl('forms.index'))->assertOk()->viewData('page');

    expect(array_column($page['props']['forms'], 'id'))->toEqualCanonicalizing(['shared', 'everywhere']);
});

it('lists every form the user can reach, and only the ones it may change as editable', function (): void {
    $this->actingAs(gateUser('es configurer'));

    /** @var array{props: array{forms: list<array{id: string, can_edit: bool}>}} $page */
    $page = $this->get(gateUrl('forms.index'))->assertOk()->viewData('page');

    expect(array_column($page['props']['forms'], 'can_edit', 'id'))->toEqual(['everywhere' => false, 'shared' => false]);
});

it('refuses an es-only user the settings screen of an en and es form', function (string $kind): void {
    $this->actingAs(gateUser($kind));

    $this->getJson(gateUrl('forms.edit', 'shared'))->assertForbidden();
})->with(['es configurer', 'es viewer']);

it('opens the settings screen to a user with every site the form reaches', function (): void {
    $this->actingAs(gateUser('both configurer'));

    $this->get(gateUrl('forms.edit', 'shared'))->assertOk();
});
