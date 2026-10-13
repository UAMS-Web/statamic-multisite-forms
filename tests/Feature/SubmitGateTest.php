<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Tests\Feature;

use Illuminate\Support\Facades\File;
use Statamic\Facades\Form;
use Statamic\Facades\Stache;
use Tests\TestCase;

/**
 * Host-bound: core's form submit route, in a real two-site host, accepts a submit
 * from a site the form is assigned to and answers 404 from one it is not (#72).
 *
 * It runs through the host's own `web` group, kernel and route registration, which
 * is what attaching the gate from the add-on depends on. Form files and stored
 * submissions go to a temporary directory, so the host's own content is never written.
 *
 * @command  php artisan test vendor/uams-web/statamic-multisite-forms/tests/Feature/SubmitGateTest.php
 */
final class SubmitGateTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/msf-host-submit-'.uniqid('', true);
        config(['statamic.forms.forms' => $this->directory]);
        Stache::store('form-submissions')->directory($this->directory.'/_submissions');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    /** A form assigned to `en` accepts a submit from the `en` site and stores it. */
    public function test_a_submit_from_an_assigned_site_is_stored(): void
    {
        $this->makeForm(['en']);

        $this->post('/!/forms/msf_host_submit', ['message' => 'hello'], ['referer' => url('/contact')])->assertRedirect();

        $this->assertSame(1, Form::find('msf_host_submit')->submissions()->count());
    }

    /** The same form answers 404 to a submit from the `es` site and stores nothing. */
    public function test_a_submit_from_an_unassigned_site_is_not_found(): void
    {
        $this->makeForm(['en']);

        $this->post('/!/forms/msf_host_submit', ['message' => 'hello'], ['referer' => url('/es/contacto')])->assertNotFound();

        $this->assertSame(0, Form::find('msf_host_submit')->submissions()->count());
    }

    /** A form with no sites accepts a submit from each site, as core does. */
    public function test_a_global_form_accepts_a_submit_from_each_site(): void
    {
        $this->makeForm(null);

        $this->post('/!/forms/msf_host_submit', ['message' => 'en'], ['referer' => url('/contact')])->assertRedirect();
        $this->post('/!/forms/msf_host_submit', ['message' => 'es'], ['referer' => url('/es/contacto')])->assertRedirect();

        $this->assertSame(2, Form::find('msf_host_submit')->submissions()->count());
    }

    /**
     * @param  list<string>|null  $sites
     */
    private function makeForm(?array $sites): void
    {
        $form = Form::make('msf_host_submit')->title('Submit')->store(true);
        if ($sites !== null) {
            $form->set('sites', $sites);
        }

        $form->save();
    }
}
