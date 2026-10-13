<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Tests\Feature;

use Illuminate\Support\Facades\File;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Form;
use Statamic\Facades\Role;
use Statamic\Facades\User;
use Tests\TestCase;

/**
 * Host-bound: on a real multisite host, an `es`-only Control Panel user does not
 * see an `en`-only form on the forms index and cannot open the settings of an
 * `en`-and-`es` form or their fields, even with `configure forms` or
 * `configure form fields` (#77, #116).
 *
 * The add-on's Integration suite covers the gate against a minimal Testbench app;
 * this run confirms it holds behind the host's full provider list, its own sites
 * and its Control Panel middleware. Form files and roles go to temporary paths, so
 * the host's own forms and roles are never written.
 *
 * @command  php artisan test vendor/uams-web/statamic-multisite-forms/tests/Feature/FormSiteAccessTest.php
 */
final class FormSiteAccessTest extends TestCase
{
    private string $formsDirectory;

    private string $rolesFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formsDirectory = sys_get_temp_dir().'/msf-host-forms-'.uniqid('', true);
        config(['statamic.forms.forms' => $this->formsDirectory]);

        $this->rolesFile = sys_get_temp_dir().'/msf-host-roles-'.uniqid('', true).'.yaml';
        Role::path($this->rolesFile);

        foreach (['msf_host_english' => ['en'], 'msf_host_shared' => ['en', 'es']] as $handle => $sites) {
            $form = Form::make($handle);
            $form->title($handle);
            $form->set('sites', $sites);
            $form->save();
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->formsDirectory);
        File::delete($this->rolesFile);

        parent::tearDown();
    }

    /**
     * A user who can access only the `es` site, with these further permissions.
     *
     * @param  list<string>  $permissions
     */
    private function spanishUser(string $id, array $permissions): UserContract
    {
        Role::make($id)->title($id)->permissions(['access cp', 'access es site', ...$permissions])->save();

        return User::make()->id($id)->email("{$id}@example.com")->assignRole($id);
    }

    /** The forms index lists the shared form and leaves out the en-only one. */
    public function test_the_index_leaves_out_a_form_of_another_site(): void
    {
        $this->actingAs($this->spanishUser('msf-host-es-viewer', [
            'view msf_host_english form submissions',
            'view msf_host_shared form submissions',
        ]));

        $page = $this->get(cp_route('forms.index'))->assertOk()->viewData('page');
        $handles = array_column($page['props']['forms'], 'id');

        $this->assertContains('msf_host_shared', $handles);
        $this->assertNotContains('msf_host_english', $handles);
    }

    /** The settings screen of a form that also reaches en is refused, configure forms or not. */
    public function test_the_settings_screen_is_refused_without_every_site(): void
    {
        $this->actingAs($this->spanishUser('msf-host-es-configurer', ['configure forms']));

        $this->getJson(cp_route('forms.edit', 'msf_host_shared'))->assertForbidden();
        $this->getJson(cp_route('forms.edit', 'msf_host_english'))->assertForbidden();
    }

    /** A super user still opens the same screen, so the refusal is the site rule's. */
    public function test_a_super_user_opens_the_settings_screen(): void
    {
        $this->actingAs(User::make()->id('msf-host-admin')->email('msf-host-admin@example.com')->makeSuper());

        $this->get(cp_route('forms.edit', 'msf_host_shared'))->assertOk();
    }

    /** The fields screen of a form that also reaches en is refused too, configure form fields or not (#116). */
    public function test_the_fields_screen_is_refused_without_every_site(): void
    {
        $this->actingAs($this->spanishUser('msf-host-es-fields', ['configure form fields']));

        $this->getJson(cp_route('blueprints.forms.edit', 'msf_host_shared'))->assertForbidden();
        $this->getJson(cp_route('blueprints.forms.edit', 'msf_host_english'))->assertForbidden();
    }

    /** A super user opens the same fields screen, so the refusal is the site rule's. */
    public function test_a_super_user_opens_the_fields_screen(): void
    {
        $this->actingAs(User::make()->id('msf-host-fields-admin')->email('msf-host-fields-admin@example.com')->makeSuper());

        $this->get(cp_route('blueprints.forms.edit', 'msf_host_shared'))->assertOk();
    }
}
