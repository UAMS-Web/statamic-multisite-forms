<?php

declare(strict_types=1);

use Statamic\Forms\Form;
use UAMSWeb\MultisiteForms\Exceptions\ReadOnlyFormException;
use UAMSWeb\MultisiteForms\Forms\SiteForm;

/**
 * A site-resolved form is a read-only copy of the base form: it carries the base
 * form's state, refuses to be saved or deleted, and leaves the base untouched (#71).
 *
 * @command  vendor/bin/pest tests/Unit/Forms/SiteFormTest.php
 */

/**
 * A base form with a value in every kind of state core's Form keeps.
 */
function baseForm(): Form
{
    $form = new Form;
    $form->handle('contact');
    $form->title('Contact');
    $form->honeypot('trap');
    $form->store(false);
    $form->email([['to' => 'base@example.com', 'subject' => 'Base']]);
    $form->set('sites', ['en', 'es']);
    $form->setSupplement('note', 'kept');

    return $form;
}

it('carries the base form state and the site it was resolved for', function (): void {
    $base = baseForm();
    $view = SiteForm::of($base, 'es');

    expect($view->handle())->toBe('contact')
        ->and($view->title())->toBe('Contact')
        ->and($view->honeypot())->toBe('trap')
        ->and($view->store())->toBeFalse()
        ->and($view->email())->toBe([['to' => 'base@example.com', 'subject' => 'Base']])
        ->and($view->get('sites'))->toBe(['en', 'es'])
        ->and($view->getSupplement('note'))->toBe('kept')
        ->and($view->site())->toBe('es')
        ->and($view->base())->toBe($base);
});

it('refuses to be saved, naming the form and the site', function (): void {
    SiteForm::of(baseForm(), 'es')->save();
})->throws(ReadOnlyFormException::class, 'The [contact] form resolved for the [es] site is read-only and cannot be saved.');

it('refuses to be saved quietly', function (): void {
    SiteForm::of(baseForm(), 'es')->saveQuietly();
})->throws(ReadOnlyFormException::class, 'cannot be saved');

it('refuses to be deleted, naming the form and the site', function (): void {
    SiteForm::of(baseForm(), 'es')->delete();
})->throws(ReadOnlyFormException::class, 'The [contact] form resolved for the [es] site is read-only and cannot be deleted.');

it('names no handle in the refusal when the form has none', function (): void {
    SiteForm::of(new Form, 'es')->save();
})->throws(ReadOnlyFormException::class, 'The [] form resolved for the [es] site');

it('refuses to be deleted quietly', function (): void {
    SiteForm::of(baseForm(), 'es')->deleteQuietly();
})->throws(ReadOnlyFormException::class, 'cannot be deleted');

it('does not share data or supplements with the base form', function (): void {
    $base = baseForm();
    $view = SiteForm::of($base, 'es');

    $view->set('sites', ['es']);
    $view->title('Contacto');
    $view->setSupplement('note', 'changed');

    expect($base->get('sites'))->toBe(['en', 'es'])
        ->and($base->title())->toBe('Contact')
        ->and($base->getSupplement('note'))->toBe('kept');
});

it('is not changed when the base form changes afterward', function (): void {
    $base = baseForm();
    $view = SiteForm::of($base, 'es');

    $base->set('sites', ['en']);
    $base->title('Renamed');

    expect($view->get('sites'))->toBe(['en', 'es'])
        ->and($view->title())->toBe('Contact');
});
