<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Statamic\Auth\File\User;
use Statamic\Facades\Form as FormFacade;
use Statamic\Facades\YAML;
use Statamic\Forms\Form;
use Symfony\Component\HttpFoundation\Response;
use UAMSWeb\MultisiteForms\Access\AccessibleSites;
use UAMSWeb\MultisiteForms\Tests\TestCase;

/**
 * A non-super user may save a form's sites only as a selection they could still
 * edit the form under: no site they cannot access, and no empty selection unless
 * they can access every site (#118, #138).
 *
 * @command  vendor/bin/pest tests/Integration/Access/AccessibleSitesTest.php
 */
beforeEach(function (): void {
    useTwoSites();
    useTemporaryFormsDirectory();
    $this->rolesFile = useTemporaryRoles();

    $form = FormFacade::make('contact');
    if (! $form instanceof Form) {
        throw new RuntimeException('The forms repository did not make a form.');
    }

    $form->title('Contact');
    $form->set('sites', ['en']);
    $form->save();
});

afterEach(function (): void {
    removeFormsDirectory();
    File::delete($this->rolesFile);
});

/**
 * A user who may configure forms, with access to the given sites.
 *
 * @param  list<string>  $sites
 */
function formsEditor(string $id, array $sites): User
{
    return userWith($id, ['access cp', 'configure forms', ...array_map(static fn (string $site): string => "access {$site} site", $sites)]);
}

/**
 * Save the form's settings with the given sites, or without a sites value for null.
 *
 * @param  array<array-key, mixed>|null  $sites
 * @return TestResponse<Response>
 */
function saveSites(TestCase $case, ?array $sites): TestResponse
{
    $payload = ['title' => 'Contact', 'honeypot' => 'trap', 'store' => false, 'email' => []];
    if ($sites !== null) {
        $payload['sites'] = $sites;
    }

    $url = cp_route('forms.update', 'contact');
    if (! is_string($url)) {
        throw new RuntimeException('The [forms.update] route did not resolve.');
    }

    return $case->patchJson($url, $payload);
}

/**
 * The sites the form file lists, or null when it lists none.
 */
function savedSites(): mixed
{
    $directory = config('statamic.forms.forms');
    $data = YAML::parse(File::get((is_string($directory) ? $directory : '').'/contact.yaml'));

    return $data['sites'] ?? null;
}

it('lets a user save the sites they can access', function (): void {
    $this->actingAs(formsEditor('en-only', ['en']));

    saveSites($this, ['en'])->assertOk();

    expect(savedSites())->toBe(['en']);
});

it('refuses a site the user cannot access, naming it, and leaves the file alone', function (array $sites): void {
    $this->actingAs(formsEditor('en-only', ['en']));

    saveSites($this, $sites)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sites' => 'This form cannot be assigned to a site you cannot access: Spanish.']);

    expect(savedSites())->toBe(['en']);
})->with([
    'another site beside their own' => [['en', 'es']],
    'only another site' => [['es']],
]);

it('refuses an empty or missing selection from a user who cannot access every site', function (?array $sites): void {
    $this->actingAs(formsEditor('en-only', ['en']));

    saveSites($this, $sites)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['sites' => 'Choose the sites this form belongs to. Leaving Sites empty would put it on every site, including sites you cannot access.']);

    expect(savedSites())->toBe(['en']);
})->with([
    'an empty selection' => [[]],
    'no sites value sent' => [null],
]);

it('lets a user who can access every site save any sites, or none', function (?array $sites, mixed $saved): void {
    $this->actingAs(formsEditor('both', ['en', 'es']));

    saveSites($this, $sites)->assertOk();

    expect(savedSites())->toBe($saved);
})->with([
    'both sites' => [['en', 'es'], ['en', 'es']],
    'an empty selection' => [[], null],
]);

it('lets a super user save a site and an empty selection', function (?array $sites, mixed $saved): void {
    $this->actingAs(superUser());

    saveSites($this, $sites)->assertOk();

    expect(savedSites())->toBe($saved);
})->with([
    'another site' => [['es'], ['es']],
    'an empty selection' => [[], null],
]);

it('passes any selection when no user is signed in', function (): void {
    $failed = false;
    new AccessibleSites()->validate('sites', [], static function () use (&$failed): void {
        $failed = true;
    });

    expect($failed)->toBeFalse();
});

it('leaves a handle naming no configured site to the configured sites rule', function (): void {
    $this->actingAs(formsEditor('en-only', ['en']));

    $messages = [];
    new AccessibleSites()->validate('sites', ['en', 'nope', ['es']], static function (string $message) use (&$messages): void {
        $messages[] = $message;
    });

    expect($messages)->toBe([]);
});
