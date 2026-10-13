<?php

declare(strict_types=1);

use Statamic\Contracts\Forms\Form as FormContract;
use Statamic\Facades\Form as FormFacade;
use Statamic\Forms\Form;
use UAMSWeb\MultisiteForms\Forms\FormResolver;
use UAMSWeb\MultisiteForms\Forms\SiteForm;

/**
 * The resolver answers whether a form is assigned to a site, and returns a
 * read-only view for one it is assigned to (#71).
 *
 * @command  vendor/bin/pest tests/Integration/Forms/FormResolverTest.php
 */
beforeEach(function (): void {
    useTwoSites();
});

/**
 * A form assigned to the given sites, or global when given null.
 *
 * @param  list<string>|null  $sites
 */
function resolvableForm(?array $sites): Form
{
    $form = new Form;
    $form->handle('contact');
    $form->title('Contact');

    if ($sites !== null) {
        $form->set('sites', $sites);
    }

    return $form;
}

it('resolves a form for a site it is assigned to', function (): void {
    $view = (new FormResolver)->resolve(resolvableForm(['en']), 'en');

    expect($view)->toBeInstanceOf(SiteForm::class)
        ->and($view?->site())->toBe('en')
        ->and($view?->handle())->toBe('contact');
});

it('returns null for a site the form is not assigned to', function (): void {
    expect((new FormResolver)->resolve(resolvableForm(['en']), 'es'))->toBeNull();
});

it('resolves a global form for every site', function (string $site): void {
    expect((new FormResolver)->resolve(resolvableForm(null), $site)?->site())->toBe($site);
})->with(['en', 'es']);

it('accepts a site object as well as a handle', function (): void {
    $resolver = new FormResolver;
    $form = resolvableForm(['es']);

    expect($resolver->resolve($form, configuredSite('es'))?->site())->toBe('es')
        ->and($resolver->resolve($form, configuredSite('en')))->toBeNull();
});

it('resolves from the base form when handed a view', function (): void {
    $resolver = new FormResolver;
    $base = resolvableForm(['en', 'es']);

    $again = $resolver->resolve($resolver->resolve($base, 'en') ?? throw new LogicException('not resolved'), 'es');

    expect($again?->site())->toBe('es')
        ->and($again?->base())->toBe($base);
});

it('keeps the base handle, so the action URL is the base form URL', function (): void {
    $base = resolvableForm(['es']);

    expect((new FormResolver)->resolve($base, 'es')?->actionUrl())->toBe($base->actionUrl());
});

it('leaves the base form unchanged when its view is changed', function (): void {
    $base = resolvableForm(['en', 'es']);

    $view = (new FormResolver)->resolve($base, 'es');
    $view?->set('sites', ['es']);
    $view?->title('Contacto');

    expect($base->get('sites'))->toBe(['en', 'es'])
        ->and($base->title())->toBe('Contact');
});

it('applies the site override row to the view it resolves (#75)', function (): void {
    $base = resolvableForm(['en', 'es']);
    $base->set('site_overrides', [['site' => 'es', 'title' => 'Contacto']]);

    $resolver = new FormResolver;

    expect($resolver->resolve($base, 'es')?->title())->toBe('Contacto')
        ->and($resolver->resolve($base, 'en')?->title())->toBe('Contact')
        ->and($base->title())->toBe('Contact');
});

it('reaches a driver form class through base(), not through the view', function (): void {
    // A driver binds its own subclass of core's Form, as statamic/eloquent-driver does (#107)
    $driverForm = new class extends Form
    {
        public function model(): string
        {
            return 'the stored row';
        }
    };
    app()->bind(FormContract::class, $driverForm::class);

    $base = FormFacade::make('contact');
    if (! $base instanceof $driverForm) {
        throw new LogicException('the contract did not resolve to the driver class');
    }

    $base->set('sites', ['en']);

    $view = (new FormResolver)->resolve($base, 'en') ?? throw new LogicException('not resolved');

    $callModel =
        // @phpstan-ignore method.notFound (model() is the driver's method, which the view deliberately does not have)
        (fn (): mixed => $view->model());

    expect($view)->not->toBeInstanceOf($driverForm::class)
        ->and($view->base())->toBe($base)
        ->and($view->base())->toBeInstanceOf($driverForm::class)
        ->and($callModel)->toThrow(BadMethodCallException::class);
});
