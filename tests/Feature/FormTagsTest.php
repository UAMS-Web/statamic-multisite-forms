<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Tests\Feature;

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Statamic\Facades\Form;
use Tests\TestCase;

/**
 * Host-bound: a page on each site of a real two-site host renders the `{{ form }}`
 * tags for a form assigned to that site, and follows `not_assigned` for one that
 * is not (#73).
 *
 * The page is an Antlers view served by a route on each site's URL, rendered
 * through the host's own kernel, view finder and exception handler, which is what
 * turns a 404 thrown from inside a tag into a 404 response rather than a view
 * error. Form files and the view go to a temporary directory, so the host's own
 * content is never written.
 *
 * @command  php artisan test vendor/uams-web/statamic-multisite-forms/tests/Feature/FormTagsTest.php
 */
final class FormTagsTest extends TestCase
{
    private const string ACTION = '/!/forms/msf_host_tags';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/msf-host-tags-'.uniqid('', true);
        config(['statamic.forms.forms' => $this->directory]);

        File::ensureDirectoryExists($this->directory.'/views');
        File::put(
            $this->directory.'/views/msf_form_probe.antlers.html',
            '<main id="probe">{{ form:create in="msf_host_tags" }}<input name="message">{{ /form:create }}</main>',
        );
        View::addLocation($this->directory.'/views');

        $this->routeProbePages();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    /** A form assigned to `en` renders on the `en` page as core renders it. */
    public function test_an_assigned_form_renders_on_its_site(): void
    {
        $this->makeForm(['en']);

        $this->get('/msf-form-probe')->assertOk()->assertSee(self::ACTION, false);
    }

    /** The same form renders nothing on the `es` page under `empty`, and logs one warning. */
    public function test_an_unassigned_form_renders_nothing_under_empty(): void
    {
        $this->makeForm(['en']);
        $warnings = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$warnings): void {
            if ($event->level === 'warning') {
                $warnings[] = $event->context;
            }
        });

        $this->get('/es/msf-form-probe')
            ->assertOk()
            ->assertSee('<main id="probe"></main>', false)
            ->assertDontSee(self::ACTION, false);

        $this->assertSame([['form' => 'msf_host_tags', 'site' => 'es']], $warnings);
    }

    /** Under `404`, the `es` page answers 404, not a view error. */
    public function test_an_unassigned_form_answers_404_under_404(): void
    {
        config(['statamic-multisite-forms.not_assigned' => '404']);
        $this->makeForm(['en']);

        $this->get('/es/msf-form-probe')->assertNotFound();
        $this->get('/msf-form-probe')->assertOk()->assertSee(self::ACTION, false);
    }

    /** A form with no sites renders on each site, as core does. */
    public function test_a_global_form_renders_on_each_site(): void
    {
        $this->makeForm(null);

        $this->get('/msf-form-probe')->assertOk()->assertSee(self::ACTION, false);
        $this->get('/es/msf-form-probe')->assertOk()->assertSee(self::ACTION, false);
    }

    /**
     * Serve the probe view on each site's URL, ahead of Statamic's catch-all
     * frontend route, which would otherwise match first.
     */
    private function routeProbePages(): void
    {
        $probes = [
            Route::middleware('web')->get('msf-form-probe', fn () => view('msf_form_probe')),
            Route::middleware('web')->get('es/msf-form-probe', fn () => view('msf_form_probe')),
        ];

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
        $form = Form::make('msf_host_tags')->title('Tags');
        if ($sites !== null) {
            $form->set('sites', $sites);
        }

        $form->save();
    }
}
