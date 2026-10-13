<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Tests\Feature;

use Illuminate\Support\Facades\File;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Facades\Form;
use Statamic\Facades\Role;
use Statamic\Facades\Stache;
use Statamic\Facades\User;
use Tests\TestCase;

/**
 * Host-bound: on a real multisite host, an `es`-only Control Panel user lists,
 * opens and exports only the `es` submission of an `en`-and-`es` form, while a
 * super user reads all three (#78).
 *
 * The add-on's Integration suite covers the scope against a minimal Testbench app;
 * this run confirms it holds behind the host's full provider list, its own sites,
 * its Control Panel middleware and its submission store. Forms, submissions and
 * roles go to temporary paths, so the host's own are never written.
 *
 * @command  php artisan test vendor/uams-web/statamic-multisite-forms/tests/Feature/SubmissionSiteScopeTest.php
 */
final class SubmissionSiteScopeTest extends TestCase
{
    private string $formsDirectory;

    private string $rolesFile;

    /** @var array<string, string> Submission ids by the name each was saved with. */
    private array $ids = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->formsDirectory = sys_get_temp_dir().'/msf-host-scope-'.uniqid('', true);
        config(['statamic.forms.forms' => $this->formsDirectory]);
        Stache::store('form-submissions')->directory($this->formsDirectory.'/_submissions');

        $this->rolesFile = sys_get_temp_dir().'/msf-host-scope-roles-'.uniqid('', true).'.yaml';
        Role::path($this->rolesFile);

        $form = Form::make('msf_host_scoped');
        $form->title('msf_host_scoped');
        $form->set('sites', ['en', 'es']);
        $form->save();

        foreach (['en', 'es', null] as $site) {
            $submission = $form->makeSubmission();
            $submission->set('name', $site ?? 'none');
            if ($site !== null) {
                $submission->set('_site', $site);
            }

            $submission->save();
            $this->ids[$site ?? 'none'] = $submission->id();
        }
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->formsDirectory);
        File::delete($this->rolesFile);

        parent::tearDown();
    }

    /** A user who can access only the `es` site and read the form's submissions. */
    private function spanishReader(): UserContract
    {
        Role::make('msf-host-es-reader')->title('msf-host-es-reader')->permissions([
            'access cp',
            'access es site',
            'view msf_host_scoped form submissions',
        ])->save();

        return User::make()->id('msf-host-es-reader')->email('msf-host-es-reader@example.com')->assignRole('msf-host-es-reader');
    }

    /** The listing shows the es submission only; a super user sees all three. */
    public function test_the_listing_shows_only_the_sites_a_user_can_access(): void
    {
        $this->actingAs($this->spanishReader());
        $rows = $this->getJson(cp_route('forms.submissions.index', 'msf_host_scoped'))->assertOk()->json('data');
        $spanish = array_column($rows, 'id');

        $this->actingAs(User::make()->id('msf-host-scope-admin')->email('msf-host-scope-admin@example.com')->makeSuper());
        $rows = $this->getJson(cp_route('forms.submissions.index', 'msf_host_scoped'))->assertOk()->json('data');
        $every = array_column($rows, 'id');

        $this->assertSame([$this->ids['es']], $spanish);
        $this->assertEqualsCanonicalizing(array_values($this->ids), $every);
    }

    /** The en submission cannot be opened; the es one can. */
    public function test_the_detail_view_of_another_site_is_not_found(): void
    {
        $this->actingAs($this->spanishReader());

        $this->get(cp_route('forms.submissions.show', ['msf_host_scoped', $this->ids['en']]))->assertNotFound();
        $this->get(cp_route('forms.submissions.show', ['msf_host_scoped', $this->ids['es']]))->assertOk();
    }

    /** The export carries the es submission only. */
    public function test_the_export_carries_only_the_sites_a_user_can_access(): void
    {
        $this->actingAs($this->spanishReader());

        $rows = json_decode((string) $this->get(cp_route('forms.export', ['msf_host_scoped', 'json']))->assertOk()->getContent(), true);

        $this->assertSame([$this->ids['es']], array_column($rows, 'id'));
    }
}
