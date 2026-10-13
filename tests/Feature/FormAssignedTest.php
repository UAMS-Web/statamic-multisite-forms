<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Tests\Feature;

use Illuminate\Contracts\View\Factory;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Statamic\Facades\Form;
use Tests\TestCase;

/**
 * Host-bound: an Antlers page and a Blade page on each site of a real two-site host
 * branch on `form:assigned`, so content around a form shows only where the form
 * renders (#60).
 *
 * Each page is a view served by a route on each site's URL, rendered through the
 * host's own kernel and view finder. Form files and the views go to a temporary
 * directory, so the host's own content is never written.
 *
 * @command  php artisan test vendor/uams-web/statamic-multisite-forms/tests/Feature/FormAssignedTest.php
 */
final class FormAssignedTest extends TestCase
{
    private const string ANTLERS = '<main id="probe">{{ if {form:assigned in="msf_host_assigned"} }}<h2>Contact us</h2>{{ /if }}'
        .'{{ form:assigned in="msf_host_assigned" }}<p>Intro</p>{{ /form:assigned }}</main>';

    private const string BLADE = '<main id="probe">@if (Statamic::tag(\'form:assigned\')->params([\'in\' => \'msf_host_assigned\'])->fetch())<h2>Contact us</h2>@endif'
        .'<s:form:assigned in="msf_host_assigned"><p>Intro</p></s:form:assigned></main>';

    private const string SHOWN = '<main id="probe"><h2>Contact us</h2><p>Intro</p></main>';

    private const string HIDDEN = '<main id="probe"></main>';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/msf-host-assigned-'.uniqid('', true);
        config(['statamic.forms.forms' => $this->directory]);

        File::ensureDirectoryExists($this->directory.'/views');
        File::put($this->directory.'/views/msf_assigned_antlers.antlers.html', self::ANTLERS);
        File::put($this->directory.'/views/msf_assigned_blade.blade.php', self::BLADE);
        View::addLocation($this->directory.'/views');

        $this->routeProbePages();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    /** A form assigned to `en` shows the content around it on `en` and hides it on `es`, in both languages. */
    public function test_content_shows_only_on_the_assigned_site(): void
    {
        $this->makeForm(['en']);

        foreach (['antlers', 'blade'] as $language) {
            $this->get("/msf-assigned-{$language}")->assertOk()->assertSee(self::SHOWN, false);
            $this->get("/es/msf-assigned-{$language}")->assertOk()->assertSee(self::HIDDEN, false);
        }
    }

    /** A form with no sites shows the content on each site. */
    public function test_content_shows_on_each_site_for_a_global_form(): void
    {
        $this->makeForm(null);

        foreach (['antlers', 'blade'] as $language) {
            $this->get("/msf-assigned-{$language}")->assertOk()->assertSee(self::SHOWN, false);
            $this->get("/es/msf-assigned-{$language}")->assertOk()->assertSee(self::SHOWN, false);
        }
    }

    /** Under `404` the check still answers rather than aborting the page. */
    public function test_the_check_does_not_abort_under_404(): void
    {
        config(['statamic-multisite-forms.not_assigned' => '404']);
        $this->makeForm(['en']);

        $this->get('/es/msf-assigned-antlers')->assertOk()->assertSee(self::HIDDEN, false);
    }

    /**
     * Serve the probe views on each site's URL, ahead of Statamic's catch-all
     * frontend route, which would otherwise match first.
     */
    private function routeProbePages(): void
    {
        $probes = [];
        foreach (['antlers', 'blade'] as $language) {
            foreach (['', 'es/'] as $prefix) {
                $probes[] = Route::middleware('web')->get(
                    "{$prefix}msf-assigned-{$language}",
                    fn (): Factory|\Illuminate\Contracts\View\View => view("msf_assigned_{$language}"),
                );
            }
        }

        $routes = new RouteCollection;
        foreach ($probes as $probe) {
            $routes->add($probe);
        }

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array($route, $probes, true)) {
                $routes->add($route);
            }
        }

        Route::setRoutes($routes);
    }

    /**
     * @param  list<string>|null  $sites
     */
    private function makeForm(?array $sites): void
    {
        $form = Form::make('msf_host_assigned')->title('Assigned');
        if ($sites !== null) {
            $form->set('sites', $sites);
        }

        $form->save();
    }
}
