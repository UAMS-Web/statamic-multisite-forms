<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use UAMSWeb\MultisiteForms\Access\SiteAccess;

/**
 * Site access is asked of core's site policy, and a handle naming no configured
 * site is never accessible, whoever asks (#77).
 *
 * @command  vendor/bin/pest tests/Integration/Access/SiteAccessTest.php
 */
beforeEach(function (): void {
    useTwoSites();
    $this->rolesFile = useTemporaryRoles();
});

afterEach(function (): void {
    File::delete($this->rolesFile);
});

it("follows the user's site permissions", function (): void {
    $user = userWith('es-only', ['access es site']);

    expect(SiteAccess::canAccess($user, 'es'))->toBeTrue()
        ->and(SiteAccess::canAccess($user, 'en'))->toBeFalse()
        ->and(SiteAccess::canAccessAny($user, ['en', 'es']))->toBeTrue()
        ->and(SiteAccess::canAccessAny($user, ['en']))->toBeFalse()
        ->and(SiteAccess::canAccessAll($user, ['es']))->toBeTrue()
        ->and(SiteAccess::canAccessAll($user, ['en', 'es']))->toBeFalse();
});

it('lets a super user access every configured site', function (): void {
    expect(SiteAccess::canAccessAll(superUser(), ['en', 'es']))->toBeTrue();
});

it('never grants a handle that names no configured site, even to a super user', function (): void {
    expect(SiteAccess::canAccess(superUser(), 'fr'))->toBeFalse()
        ->and(SiteAccess::canAccess(userWith('fr-named', ['access fr site']), 'fr'))->toBeFalse();
});

it('lists the handles of every configured site', function (): void {
    expect(SiteAccess::allHandles())->toBe(['en', 'es']);
});
