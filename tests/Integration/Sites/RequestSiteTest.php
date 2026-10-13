<?php

declare(strict_types=1);

use Statamic\Facades\Site;
use Statamic\Sites\Site as SiteObject;
use UAMSWeb\MultisiteForms\Sites\RequestSite;

/**
 * The submit site is core's own rule: the site the referrer falls under, else
 * the default site (#71).
 *
 * @command  vendor/bin/pest tests/Integration/Sites/RequestSiteTest.php
 */
beforeEach(function (): void {
    config(['statamic.system.multisite' => true]);
    Site::setSites([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US'],
        'es' => ['name' => 'Spanish', 'url' => 'http://localhost/es/', 'locale' => 'es_ES'],
    ]);
});

it('names the site the referrer falls under', function (string $referrer, string $site): void {
    request()->headers->set('referer', $referrer);

    expect((new RequestSite)->forSubmission()->handle())->toBe($site);
})->with([
    'English page' => ['http://localhost/contact', 'en'],
    'Spanish page' => ['http://localhost/es/contacto', 'es'],
    'Spanish home' => ['http://localhost/es/', 'es'],
]);

it('falls back to the default site with no referrer', function (): void {
    request()->headers->remove('referer');

    // `en` is the first configured site, which core takes as the default
    expect((new RequestSite)->forSubmission()->handle())->toBe('en');
});

it('falls back to the default site for a referrer on no site', function (): void {
    request()->headers->set('referer', 'https://elsewhere.example/es/');

    expect((new RequestSite)->forSubmission()->handle())->toBe('en');
});

it('names the current site for a page render', function (): void {
    Site::setCurrent('es');

    expect((new RequestSite)->forPage()->handle())->toBe('es');
});

it('reads the handle of a site given as an object or a handle', function (): void {
    expect(RequestSite::handle(configuredSite('es')))->toBe('es')
        ->and(RequestSite::handle('fr'))->toBe('fr');
});

it('refuses a site object with no handle', function (): void {
    RequestSite::handle(new SiteObject(null, ['url' => '/']));
})->throws(LogicException::class, 'A site has no handle');

it('refuses to name a site when the install defines none', function (string $surface): void {
    Site::setSites([]);
    request()->headers->set('referer', 'http://localhost/es/');

    $surface === 'submission' ? (new RequestSite)->forSubmission() : (new RequestSite)->forPage();
})->with(['submission', 'page'])->throws(LogicException::class, 'No site could be determined');
