<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Tests\Feature;

use Illuminate\Support\Facades\File;
use Statamic\Facades\Form;
use Statamic\Facades\User;
use Statamic\Facades\YAML;
use Tests\TestCase;

/**
 * Host-bound: the form settings screen of a real multisite host shows the Sites
 * section, and saving it writes `sites` to the form file (#70).
 *
 * The add-on's own Integration suite covers the same requests against a minimal
 * Testbench app; this run confirms the section survives the host's full provider
 * list, its own sites and its Control Panel middleware. Form files go to a
 * temporary directory, so the host's own forms are never written.
 *
 * @command  php artisan test vendor/uams-web/statamic-multisite-forms/tests/Feature/SitesConfigSectionTest.php
 */
final class SitesConfigSectionTest extends TestCase
{
    private string $formsDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formsDirectory = sys_get_temp_dir().'/msf-host-forms-'.uniqid('', true);
        config(['statamic.forms.forms' => $this->formsDirectory]);

        $user = User::make()->id('msf-host-admin')->email('msf-host-admin@example.com')->makeSuper();
        $this->actingAs($user);

        $form = Form::make('msf_host_contact');
        $form->title('Contact');
        $form->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->formsDirectory);

        parent::tearDown();
    }

    /** The settings screen carries a Sites section holding the `sites` field. */
    public function test_the_settings_screen_shows_the_sites_section(): void
    {
        $response = $this->get(cp_route('forms.edit', 'msf_host_contact'))->assertOk();

        $page = $response->viewData('page');
        $fields = [];
        foreach ($page['props']['blueprint']['tabs'] as $tab) {
            foreach ($tab['sections'] as $section) {
                if ($section['display'] === 'Sites') {
                    foreach ($section['fields'] as $field) {
                        $fields[$field['handle']] = $field['type'];
                    }
                }
            }
        }

        $this->assertSame(['sites' => 'sites'], $fields);
    }

    /** Saving a selection writes it to the form file, and clearing it removes the key. */
    public function test_saving_the_section_writes_the_form_file(): void
    {
        $url = cp_route('forms.update', 'msf_host_contact');
        $file = $this->formsDirectory.'/msf_host_contact.yaml';

        $this->patchJson($url, ['title' => 'Contact', 'sites' => ['es']])->assertOk();
        $this->assertSame(['es'], YAML::file($file)->parse()['sites'] ?? null);

        $this->patchJson($url, ['title' => 'Contact', 'sites' => []])->assertOk();
        $this->assertArrayNotHasKey('sites', YAML::file($file)->parse());
    }
}
