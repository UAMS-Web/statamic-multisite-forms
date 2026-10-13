<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Tests\Feature;

use Illuminate\Contracts\View\Factory;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Statamic\Facades\Form;
use Statamic\Facades\Stache;
use Statamic\Facades\YAML;
use Tests\TestCase;

/**
 * Host-bound: Phase 1's exit criterion, end to end in a real two-site host (#67).
 *
 * One form, assigned to `en` only, renders on the `en` page and not on the `es`
 * page, answers 404 to a submit from `es` and stores nothing, and stores a submit
 * from `en` with `_site: en` in the file on disk. Form files, submissions and the
 * page's view go to a temporary directory, so the host's own content is never written.
 *
 * @command  php artisan test vendor/uams-web/statamic-multisite-forms/tests/Feature/PhaseOneExitTest.php
 */
final class PhaseOneExitTest extends TestCase
{
    private const string ACTION = '/!/forms/msf_phase_one';

    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().'/msf-host-phase-one-'.uniqid('', true);
        config(['statamic.forms.forms' => $this->directory]);
        Stache::store('form-submissions')->directory($this->directory.'/_submissions');

        File::ensureDirectoryExists($this->directory.'/views');
        File::put(
            $this->directory.'/views/msf_phase_one.antlers.html',
            '<main id="page">{{ form:create in="msf_phase_one" }}<input name="message">{{ /form:create }}</main>',
        );
        View::addLocation($this->directory.'/views');
        $this->routePages();

        Form::make('msf_phase_one')->title('Phase one')->store(true)->set('sites', ['en'])->save();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_a_form_assigned_to_en_renders_submits_and_records_only_on_en(): void
    {
        $this->get('/msf-phase-one')->assertOk()->assertSee(self::ACTION, false);
        $this->get('/es/msf-phase-one')->assertOk()->assertSee('<main id="page"></main>', false);

        $this->post(self::ACTION, ['message' => 'es'], ['referer' => url('/es/msf-phase-one')])->assertNotFound();
        $this->assertSame([], $this->storedSites());

        $this->post(self::ACTION, ['message' => 'en'], ['referer' => url('/msf-phase-one')])->assertRedirect();
        $this->assertSame(['en'], $this->storedSites());
    }

    /**
     * Serve the page on each site's URL, ahead of Statamic's catch-all frontend route.
     */
    private function routePages(): void
    {
        $pages = [
            Route::middleware('web')->get('msf-phase-one', fn (): Factory|\Illuminate\Contracts\View\View => view('msf_phase_one')),
            Route::middleware('web')->get('es/msf-phase-one', fn (): Factory|\Illuminate\Contracts\View\View => view('msf_phase_one')),
        ];

        $routes = new RouteCollection;
        foreach ($pages as $page) {
            $routes->add($page);
        }

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! in_array($route, $pages, true)) {
                $routes->add($route);
            }
        }

        Route::setRoutes($routes);
    }

    /**
     * The `_site` of each stored submission file of the form.
     *
     * @return list<string|null>
     */
    private function storedSites(): array
    {
        $files = File::glob($this->directory.'/_submissions/msf_phase_one/*.yaml');

        return array_values(array_map(fn (string $file): mixed => YAML::parse(File::get($file))['_site'] ?? null, $files));
    }
}
