<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Tests\Feature;

use Illuminate\Support\Facades\File;
use Statamic\Facades\Form;
use Statamic\Facades\FormSubmission;
use Statamic\Facades\Stache;
use Tests\TestCase;

/**
 * Host-bound: on a real multisite host, `multisite-forms:attribute` records a
 * site on a form's unattributed submissions, a dry run changes nothing, and an
 * unknown site or one the form is not assigned to is refused (#81).
 *
 * The add-on's Integration suite covers the command against a minimal Testbench
 * app; this run confirms it is registered, and resolves the host's own sites,
 * behind the host's full provider list. Forms and submissions go to a temporary
 * path, so the host's own are never written.
 *
 * @command  php artisan test vendor/uams-web/statamic-multisite-forms/tests/Feature/AttributeCommandTest.php
 */
final class AttributeCommandTest extends TestCase
{
    private string $formsDirectory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->formsDirectory = sys_get_temp_dir().'/msf-host-attribute-'.uniqid('', true);
        config(['statamic.forms.forms' => $this->formsDirectory]);
        Stache::store('form-submissions')->directory($this->formsDirectory.'/_submissions');

        $form = Form::make('msf_host_attribute');
        $form->title('msf_host_attribute');
        $form->set('sites', ['en', 'es']);
        $form->save();

        foreach (['en', 'es', null, null] as $index => $site) {
            $submission = $form->makeSubmission();
            // Core's default id has 0.1ms resolution, so two saved that close would share one
            $submission->id("msf-host-attribute-{$index}");
            $submission->set('name', ($site ?? 'none').$index);
            if ($site !== null) {
                $submission->set('_site', $site);
            }

            $submission->save();
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->formsDirectory);

        parent::tearDown();
    }

    /** @return array<string, mixed> Each submission's name, with the site it records. */
    private function recordedSites(): array
    {
        $sites = [];
        foreach (FormSubmission::whereForm('msf_host_attribute') as $submission) {
            $sites[$submission->get('name')] = $submission->get('_site');
        }

        ksort($sites);

        return $sites;
    }

    /** A dry run reports two and changes nothing; the real run records the site on those two only. */
    public function test_it_attributes_the_unattributed_submissions(): void
    {
        $this->artisan('multisite-forms:attribute', ['form' => 'msf_host_attribute', 'site' => 'es', '--dry-run' => true])
            ->expectsOutputToContain('Would attribute 2 submissions of [msf_host_attribute] to [es].')
            ->assertSuccessful();
        $this->assertSame(['en0' => 'en', 'es1' => 'es', 'none2' => null, 'none3' => null], $this->recordedSites());

        $this->artisan('multisite-forms:attribute', ['form' => 'msf_host_attribute', 'site' => 'es'])
            ->expectsOutputToContain('Attributed 2 submissions of [msf_host_attribute] to [es].')
            ->assertSuccessful();
        $this->assertSame(['en0' => 'en', 'es1' => 'es', 'none2' => 'es', 'none3' => 'es'], $this->recordedSites());
    }

    /** A site the host does not configure is refused, and nothing changes. */
    public function test_it_refuses_an_unknown_site(): void
    {
        $this->artisan('multisite-forms:attribute', ['form' => 'msf_host_attribute', 'site' => 'msf-no-such-site'])
            ->expectsOutputToContain('There is no site [msf-no-such-site].')
            ->assertFailed();

        $this->assertSame(['en0' => 'en', 'es1' => 'es', 'none2' => null, 'none3' => null], $this->recordedSites());
    }
}
