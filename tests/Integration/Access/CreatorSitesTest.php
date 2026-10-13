<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Statamic\Auth\File\User as UserObject;
use Statamic\Events\FormCreating;
use Statamic\Facades\Form as FormFacade;
use Statamic\Facades\YAML;
use Statamic\Forms\Form;
use UAMSWeb\MultisiteForms\Access\CreatorSites;
use UAMSWeb\MultisiteForms\Tests\TestCase;

/**
 * A form created by a user who cannot access every site is assigned to the
 * sites they can access, so they can still edit it; every other creation is
 * left as core makes it (#79).
 *
 * @command  vendor/bin/pest tests/Integration/Access/CreatorSitesTest.php
 */
beforeEach(function (): void {
    useTwoSites();
    useTemporaryFormsDirectory();
    $this->rolesFile = useTemporaryRoles();
});

afterEach(function (): void {
    removeFormsDirectory();
    File::delete($this->rolesFile);
});

/**
 * A user with `configure forms` who can access the given sites.
 *
 * @param  list<string>  $sites
 */
function creatorWith(string $id, array $sites): UserObject
{
    return userWith($id, ['access cp', 'configure forms', ...array_map(static fn (string $site): string => "access {$site} site", $sites)]);
}

/**
 * The parsed file of a created form.
 *
 * @return array<string, mixed>
 */
function createdFormFile(string $handle): array
{
    /** @var array<string, mixed> */
    return YAML::file(formsDirectory()."/{$handle}.yaml")->parse();
}

/**
 * The URL of a Control Panel route, for a form when given.
 *
 * @throws RuntimeException When the route does not resolve to a URL.
 */
function creatorUrl(string $route, ?string $handle = null): string
{
    $url = $handle === null ? cp_route($route) : cp_route($route, $handle);
    if (! is_string($url)) {
        throw new RuntimeException("The [{$route}] route did not resolve.");
    }

    return $url;
}

/**
 * Create a form through core's Control Panel endpoint, as the screen does.
 */
function createThroughScreen(TestCase $test, string $handle): void
{
    $test->postJson(creatorUrl('forms.store'), ['title' => ucfirst($handle), 'handle' => $handle])->assertOk();
}

it('assigns a form to the sites its creator can access when they cannot access every site', function (): void {
    $this->actingAs(creatorWith('es-creator', ['es']));

    createThroughScreen($this, 'spanish');

    expect(createdFormFile('spanish')['sites'] ?? null)->toBe(['es']);
});

it("lets that creator open the new form's settings afterward", function (): void {
    $this->actingAs(creatorWith('es-creator', ['es']));

    createThroughScreen($this, 'spanish');

    $this->get(creatorUrl('forms.edit', 'spanish'))->assertOk();
});

it('leaves the form without sites for a creator covering every site', function (string $kind): void {
    $this->actingAs($kind === 'super' ? superUser() : creatorWith('both-creator', ['en', 'es']));

    createThroughScreen($this, 'everyone');

    expect(createdFormFile('everyone'))->not->toHaveKey('sites');
})->with(['super', 'a user with both sites']);

it('leaves the form without sites on a single-site install, where every user covers the one site', function (): void {
    config(['statamic.system.multisite' => false]);
    $this->actingAs(creatorWith('es-creator', ['es']));

    createThroughScreen($this, 'single');

    expect(createdFormFile('single'))->not->toHaveKey('sites');
});

it('keeps sites a new form already lists', function (): void {
    $this->actingAs(creatorWith('es-creator', ['es']));

    $form = FormFacade::make('assigned');
    if (! $form instanceof Form) {
        throw new RuntimeException('The forms repository did not make a form.');
    }

    $form->set('sites', ['en']);
    $form->save();

    expect(createdFormFile('assigned')['sites'] ?? null)->toBe(['en']);
});

it('assigns nothing with no signed-in user, as in a console command', function (): void {
    $form = FormFacade::make('console');
    if (! $form instanceof Form) {
        throw new RuntimeException('The forms repository did not make a form.');
    }

    $form->save();

    expect(createdFormFile('console'))->not->toHaveKey('sites');
});

it('assigns nothing for a creator who can access no site', function (): void {
    $this->actingAs(creatorWith('no-site-creator', []));

    $form = FormFacade::make('nowhere');
    if (! $form instanceof Form) {
        throw new RuntimeException('The forms repository did not make a form.');
    }

    $form->save();

    expect(createdFormFile('nowhere'))->not->toHaveKey('sites');
});

it("ignores a form that is not core's form class, rather than failing the save", function (): void {
    $this->actingAs(creatorWith('es-creator', ['es']));
    $other = new stdClass;

    CreatorSites::creating(new FormCreating($other));

    expect(get_object_vars($other))->toBe([]);
});

it('assigns only when the form is created, never when an existing form is saved again', function (): void {
    $form = FormFacade::make('existing');
    if (! $form instanceof Form) {
        throw new RuntimeException('The forms repository did not make a form.');
    }

    $form->save();

    $this->actingAs(creatorWith('es-creator', ['es']));
    $form->title('Existing, renamed');
    $form->save();

    expect(createdFormFile('existing'))->not->toHaveKey('sites');
});
