<?php

declare(strict_types=1);

use Statamic\Forms\Form;
use UAMSWeb\MultisiteForms\Forms\SiteForm;

/**
 * Hooks registered on core's Form, which core files under the class they were
 * registered on, also run for a site-resolved view (#71).
 *
 * @command  vendor/bin/pest tests/Integration/Forms/SiteFormHooksTest.php
 */

/**
 * A form whose handle and title make its augmentation readable.
 */
function hookedForm(): Form
{
    $form = new Form;
    $form->handle('contact');
    $form->title('Contact');

    return $form;
}

it('runs augmented hooks registered on core Form for the view, as for the base', function (): void {
    $calls = new ArrayObject;
    Form::hook('augmented', function (mixed $augmented, Closure $next) use ($calls): mixed {
        $calls[] = 'core';

        return $next($augmented);
    });

    hookedForm()->augmented();
    expect($calls->getArrayCopy())->toBe(['core']);

    SiteForm::of(hookedForm(), 'es')->augmented();
    expect($calls->getArrayCopy())->toBe(['core', 'core']);
});

it('runs hooks registered on core Form before those registered on the view class', function (): void {
    $order = new ArrayObject;
    SiteForm::hook('augmented', function (mixed $augmented, Closure $next) use ($order): mixed {
        $order[] = 'view';

        return $next($augmented);
    });
    Form::hook('augmented', function (mixed $augmented, Closure $next) use ($order): mixed {
        $order[] = 'core';

        return $next($augmented);
    });

    SiteForm::of(hookedForm(), 'es')->augmented();

    expect($order->getArrayCopy())->toBe(['core', 'view']);
});

it('runs a view-class hook only for the view, not for the base form', function (): void {
    $calls = new ArrayObject;
    SiteForm::hook('augmented', function (mixed $augmented, Closure $next) use ($calls): mixed {
        $calls[] = 'view';

        return $next($augmented);
    });

    hookedForm()->augmented();

    expect($calls->getArrayCopy())->toBe([]);
});
