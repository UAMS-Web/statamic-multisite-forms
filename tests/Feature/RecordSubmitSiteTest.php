<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Tests\Feature;

use Illuminate\Support\Facades\File;
use Statamic\Facades\Form;
use Statamic\Facades\Stache;
use Statamic\Facades\YAML;
use Tests\TestCase;

/**
 * Host-bound: a submit from a page on each site of a real two-site host stores a
 * submission file whose `_site` is that site, whatever the request says (#74).
 *
 * It reads the stored files themselves rather than the Stache, so it shows what
 * lands on disk. Form files and submissions go to a temporary directory, so the
 * host's own content is never written.
 *
 * @command  php artisan test vendor/uams-web/statamic-multisite-forms/tests/Feature/RecordSubmitSiteTest.php
 */
final class RecordSubmitSiteTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/msf-host-site-'.uniqid('', true);
        config(['statamic.forms.forms' => $this->directory]);
        Stache::store('form-submissions')->directory($this->directory.'/_submissions');

        Form::make('msf_host_site')->title('Site')->store(true)->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    /** A submit from each site stores a file whose `_site` is that site. */
    public function test_each_site_is_recorded_in_the_stored_file(): void
    {
        $this->post('/!/forms/msf_host_site', ['message' => 'en'], ['referer' => url('/contact')])->assertRedirect();
        $this->post('/!/forms/msf_host_site', ['message' => 'es'], ['referer' => url('/es/contacto')])->assertRedirect();

        $this->assertSame(['en', 'es'], $this->storedSites());
    }

    /** A `_site` in the request body does not change the recorded site. */
    public function test_a_forged_site_is_ignored(): void
    {
        $this->post('/!/forms/msf_host_site', ['message' => 'forged', '_site' => 'es'], ['referer' => url('/contact')])->assertRedirect();

        $this->assertSame(['en'], $this->storedSites());
    }

    /**
     * The `_site` of each stored submission file, sorted.
     *
     * @return list<string|null>
     */
    private function storedSites(): array
    {
        $files = File::glob($this->directory.'/_submissions/msf_host_site/*.yaml');
        $this->assertNotEmpty($files, 'No submission file was stored.');

        $sites = array_map(fn (string $file): mixed => YAML::parse(File::get($file))['_site'] ?? null, $files);
        sort($sites);

        return $sites;
    }
}
