<?php

declare(strict_types=1);

use Statamic\Forms\Form;
use UAMSWeb\MultisiteForms\Forms\Overrides;
use UAMSWeb\MultisiteForms\Forms\SiteForm;

/**
 * A site's `site_overrides` row supplies its title, email and store, and every
 * value the row leaves out falls back to the base form's (#75).
 *
 * @command  vendor/bin/pest tests/Unit/Forms/OverridesTest.php
 */

/** The base recipients every override test starts from. */
const BASE_EMAIL = [['to' => 'base@example.com', 'subject' => 'Base']];

/**
 * A form shared by `en` and `es` with base values, and the given override rows when any.
 */
function overriddenForm(mixed $rows = null): Form
{
    $form = new Form;
    $form->handle('contact');
    $form->title('Contact');
    $form->email(BASE_EMAIL);
    $form->store(true);
    $form->set('sites', ['en', 'es']);

    if ($rows !== null) {
        $form->set(Overrides::KEY, $rows);
    }

    return $form;
}

/**
 * The form's view for a site, with that site's overrides applied.
 */
function overriddenView(Form $form, string $site): SiteForm
{
    $view = SiteForm::of($form, $site);
    Overrides::apply($view);

    return $view;
}

it('applies each value the site row sets', function (string $key, mixed $value): void {
    $view = overriddenView(overriddenForm([['site' => 'es', $key => $value]]), 'es');

    expect($view->{$key}())->toBe($value);
})->with([
    'title' => ['title', 'Contacto'],
    'email' => ['email', [['to' => 'es@example.com', 'subject' => 'ES']]],
    'store' => ['store', false],
]);

it('keeps the base value for each key the site row leaves out', function (string $key, mixed $base): void {
    // The row overrides one of the other two keys, so it exists and still leaves this one out
    $other = $key === 'title' ? ['store' => false] : ['title' => 'Contacto'];
    $view = overriddenView(overriddenForm([['site' => 'es', ...$other]]), 'es');

    expect($view->{$key}())->toBe($base);
})->with([
    'title' => ['title', 'Contact'],
    'email' => ['email', BASE_EMAIL],
    'store' => ['store', true],
]);

it('keeps every base value for a site with no row', function (): void {
    $view = overriddenView(overriddenForm([['site' => 'es', 'title' => 'Contacto', 'email' => [['to' => 'es@example.com']], 'store' => false]]), 'en');

    expect($view->title())->toBe('Contact')
        ->and($view->email())->toBe(BASE_EMAIL)
        ->and($view->store())->toBeTrue();
});

it('keeps every base value for a form with no overrides', function (mixed $rows): void {
    $view = overriddenView(overriddenForm($rows), 'es');

    expect($view->title())->toBe('Contact')
        ->and($view->email())->toBe(BASE_EMAIL)
        ->and($view->store())->toBeTrue();
})->with([
    'no key' => [null],
    'empty list' => [[]],
    'a string' => ['es'],
]);

it('replaces the base email list with the overriding one, never merging them', function (): void {
    $es = [['to' => 'es@example.com', 'subject' => 'ES']];
    $view = overriddenView(overriddenForm([['site' => 'es', 'email' => $es]]), 'es');

    expect($view->email())->toBe($es);
});

it('ignores a key a row may not override, without error', function (): void {
    $view = overriddenView(overriddenForm([['site' => 'es', 'title' => 'Contacto', 'redirect' => '/gracias', 'handle' => 'other']]), 'es');

    expect($view->title())->toBe('Contacto')
        ->and($view->handle())->toBe('contact')
        ->and($view->get('redirect'))->toBeNull();
});

it('keeps the base value for a blank or wrongly typed row value', function (string $key, mixed $value, mixed $base): void {
    $view = overriddenView(overriddenForm([['site' => 'es', $key => $value]]), 'es');

    expect($view->{$key}())->toBe($base);
})->with([
    'null title' => ['title', null, 'Contact'],
    'empty title' => ['title', '', 'Contact'],
    'null email' => ['email', null, BASE_EMAIL],
    'empty email list' => ['email', [], BASE_EMAIL],
    'email as a string' => ['email', 'es@example.com', BASE_EMAIL],
    'null store' => ['store', null, true],
    'store as another string' => ['store', 'no', true],
    'store as a number' => ['store', 0, true],
]);

it('reads the store value the editor saves as well as a boolean (#76)', function (bool $base, mixed $value, bool $expected): void {
    $form = overriddenForm([['site' => 'es', 'store' => $value]]);
    $form->store($base);

    expect(overriddenView($form, 'es')->store())->toBe($expected);
})->with([
    'the editor\'s "true" over a form that does not store' => [false, Overrides::STORE, true],
    'the editor\'s "false" over a form that stores' => [true, Overrides::DO_NOT_STORE, false],
    'true over a form that does not store' => [false, true, true],
    'false over a form that stores' => [true, false, false],
]);

it('resolves from the first row for a site that has two', function (): void {
    $view = overriddenView(overriddenForm([
        ['site' => 'es', 'title' => 'Primero'],
        ['site' => 'es', 'title' => 'Segundo'],
    ]), 'es');

    expect($view->title())->toBe('Primero');
});

it('skips rows that are not lists or name no site', function (): void {
    $view = overriddenView(overriddenForm(['es', ['title' => 'No site'], ['site' => null, 'title' => 'Null site'], ['site' => 'es', 'title' => 'Contacto']]), 'es');

    expect($view->title())->toBe('Contacto');
});

it('leaves the base form unchanged', function (): void {
    $base = overriddenForm([['site' => 'es', 'title' => 'Contacto', 'email' => [['to' => 'es@example.com']], 'store' => false]]);

    overriddenView($base, 'es');

    expect($base->title())->toBe('Contact')
        ->and($base->email())->toBe(BASE_EMAIL)
        ->and($base->store())->toBeTrue();
});

it('finds no row for a site the overrides do not name', function (): void {
    expect(Overrides::rowFor(overriddenForm([['site' => 'es', 'title' => 'Contacto']]), 'en'))->toBeNull()
        ->and(Overrides::rowFor(overriddenForm([['site' => 'es', 'title' => 'Contacto']]), 'es'))->toBe(['site' => 'es', 'title' => 'Contacto']);
});
