<?php

declare(strict_types=1);

use Statamic\Forms\Form;
use UAMSWeb\MultisiteForms\Forms\Assignment;

/**
 * A form's `sites` key decides which sites it belongs to, and no key, or an
 * empty list, means every site (#71).
 *
 * @command  vendor/bin/pest tests/Unit/Forms/AssignmentTest.php
 */

/**
 * A form whose `sites` key holds a value, or that has no `sites` key when given nothing.
 */
function assignedForm(mixed ...$sites): Form
{
    $form = new Form;
    $form->handle('contact');

    if ($sites !== []) {
        $form->set('sites', $sites[0]);
    }

    return $form;
}

it('assigns a form to the sites it lists and to no other', function (): void {
    $form = assignedForm(['en', 'es']);

    expect(Assignment::covers($form, 'en'))->toBeTrue()
        ->and(Assignment::covers($form, 'es'))->toBeTrue()
        ->and(Assignment::covers($form, 'fr'))->toBeFalse()
        ->and(Assignment::isGlobal($form))->toBeFalse();
});

it('assigns a form with no sites key, or an empty one, to every site', function (Form $form): void {
    expect(Assignment::sites($form))->toBe([])
        ->and(Assignment::isGlobal($form))->toBeTrue()
        ->and(Assignment::covers($form, 'en'))->toBeTrue()
        ->and(Assignment::covers($form, 'any-site'))->toBeTrue();
})->with([
    'no key' => fn (): Form => assignedForm(),
    'empty list' => fn (): Form => assignedForm([]),
    'null' => fn (): Form => assignedForm(null),
    'empty string' => fn (): Form => assignedForm(''),
]);

it('reads a single handle written as a string as one site', function (): void {
    $form = assignedForm('es');

    expect(Assignment::sites($form))->toBe(['es'])
        ->and(Assignment::covers($form, 'es'))->toBeTrue()
        ->and(Assignment::covers($form, 'e'))->toBeFalse();
});

it('drops blanks, repeats and values that are not handles, and keeps file order', function (): void {
    $form = assignedForm(['es', '', 'en', 'es', 3, null, ['en']]);

    expect(Assignment::sites($form))->toBe(['es', 'en']);
});

it('treats a list holding no usable handle as every site', function (): void {
    $form = assignedForm(['', null]);

    expect(Assignment::isGlobal($form))->toBeTrue()
        ->and(Assignment::covers($form, 'en'))->toBeTrue();
});

it('matches handles exactly, not loosely or by case', function (string $site): void {
    expect(Assignment::covers(assignedForm(['en']), $site))->toBeFalse();
})->with(['EN', 'en ', ' en', 'e']);
