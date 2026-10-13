<?php

/**
 * Pest configuration for the add-on.
 *
 * Integration tests boot a minimal Statamic through Orchestra Testbench via
 * `Tests\TestCase`; Unit tests stay on PHPUnit's own base class and boot
 * nothing. Shared helpers and custom expectations go here when the suite
 * grows them.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Statamic\Auth\File\User as UserObject;
use Statamic\Auth\RoleRepository as FileRoleRepository;
use Statamic\Facades\Role;
use Statamic\Facades\Site;
use Statamic\Facades\User;
use Statamic\Sites\Site as SiteObject;
use UAMSWeb\MultisiteForms\Tests\TestCase;

pest()->extend(TestCase::class)->in('Integration');

/**
 * A configured site by handle, for a test that needs the site object.
 *
 * @throws RuntimeException When the test configured no site with that handle.
 */
function configuredSite(string $handle): SiteObject
{
    $site = Site::get($handle);
    if (! $site instanceof SiteObject) {
        throw new RuntimeException("The test configured no [{$handle}] site.");
    }

    return $site;
}

/**
 * Make the install multisite with two sites, `en` at `/` (the default) and `es` at `/es/`.
 *
 * Multisite needs the Pro edition, which the Control Panel checks on every request.
 */
function useTwoSites(): void
{
    config(['statamic.editions.pro' => true, 'statamic.system.multisite' => true]);
    Site::setSites([
        'en' => ['name' => 'English', 'url' => 'http://localhost/', 'locale' => 'en_US'],
        'es' => ['name' => 'Spanish', 'url' => 'http://localhost/es/', 'locale' => 'es_ES'],
    ]);
}

/**
 * Point form files at a directory of this test's own, so parallel workers never share one.
 *
 * Remove it with {@see removeFormsDirectory()} after the test.
 */
function useTemporaryFormsDirectory(): string
{
    config(['statamic.forms.forms' => sys_get_temp_dir().'/msf-forms-'.uniqid('', true)]);

    return formsDirectory();
}

/**
 * The directory form files are read from and written to.
 *
 * @throws RuntimeException When the configured value is not a path.
 */
function formsDirectory(): string
{
    $directory = config('statamic.forms.forms');
    if (! is_string($directory)) {
        throw new RuntimeException('statamic.forms.forms is not a directory path.');
    }

    return $directory;
}

/**
 * Delete the forms directory a test pointed at.
 */
function removeFormsDirectory(): void
{
    File::deleteDirectory(formsDirectory());
}

/**
 * A super user, held in memory only, to sign in to the Control Panel as.
 *
 * @throws RuntimeException When the users repository makes something other than a user.
 */
function superUser(): UserObject
{
    $user = User::make();
    if (! $user instanceof UserObject) {
        throw new RuntimeException('The users repository did not make a user.');
    }

    $user->id('admin');
    $user->email('admin@example.com');
    $user->makeSuper();

    return $user;
}

/**
 * Point user roles at a file of this test's own, so parallel workers never share one.
 *
 * Pass the path it returns to `File::delete()` after the test.
 *
 * @throws RuntimeException When the bound roles repository does not read a file.
 */
function useTemporaryRoles(): string
{
    $path = sys_get_temp_dir().'/msf-roles-'.uniqid('', true).'.yaml';

    $roles = Role::getFacadeRoot();
    if (! $roles instanceof FileRoleRepository) {
        throw new RuntimeException('The roles repository does not read a roles file.');
    }

    $roles->path($path);

    return $path;
}

/**
 * A user held in memory with one role of its own carrying exactly these permissions.
 *
 * The role is saved to the roles file {@see useTemporaryRoles()} pointed at. The id
 * keys core's permission cache, so give every user in a test its own.
 *
 * @param  list<string>  $permissions
 *
 * @throws RuntimeException When the users repository makes something other than a user.
 */
function userWith(string $id, array $permissions): UserObject
{
    $role = Role::make($id);
    $role->title($id);
    $role->permissions($permissions);
    $role->save();

    $user = User::make();
    if (! $user instanceof UserObject) {
        throw new RuntimeException('The users repository did not make a user.');
    }

    $user->id($id);
    $user->email("{$id}@example.com");
    $user->assignRole($id);

    return $user;
}
