<?php

declare(strict_types=1);

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Http\Kernel as KernelContract;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\TestResponse;
use Statamic\Events\FormSubmitted;
use Statamic\Facades\Form as FormFacade;
use Statamic\Facades\Stache;
use Statamic\Forms\Form;
use Statamic\Stache\Stores\Store;
use UAMSWeb\MultisiteForms\Forms\FormResolver;
use UAMSWeb\MultisiteForms\Forms\SiteForm;
use UAMSWeb\MultisiteForms\Http\Middleware\ResolveSubmittedForm;
use UAMSWeb\MultisiteForms\Http\SubmitGate;
use UAMSWeb\MultisiteForms\Sites\RequestSite;
use UAMSWeb\MultisiteForms\Tests\TestCase;

/**
 * Core's form submit route answers 404 for a form not assigned to the submit
 * site, and submits the site's view of a form that is (#72).
 *
 * @command  vendor/bin/pest tests/Integration/Http/SubmitGateTest.php
 */
beforeEach(function (): void {
    useTwoSites();
    $directory = useTemporaryFormsDirectory();
    $store = Stache::store('form-submissions');
    if (! $store instanceof Store) {
        throw new RuntimeException('The Stache has no form submissions store.');
    }

    $store->directory($directory.'/_submissions');
});

afterEach(function (): void {
    removeFormsDirectory();
});

/**
 * Save a `contact` form that stores its submissions, assigned to the given sites or global.
 *
 * @param  list<string>|null  $sites
 */
function submittableForm(?array $sites): Form
{
    $form = new Form;
    $form->handle('contact');
    $form->title('Contact');
    $form->store(true);
    if ($sites !== null) {
        $form->set('sites', $sites);
    }

    $form->save();

    return $form;
}

/**
 * Submit the `contact` form from a page on the given site.
 *
 * @return TestResponse<Symfony\Component\HttpFoundation\Response>
 */
function submitFrom(TestCase $case, string $page): TestResponse
{
    return $case->post('/!/forms/contact', ['message' => 'hello'], ['referer' => $page]);
}

/**
 * How many submissions the `contact` form has stored.
 */
function storedSubmissions(): int
{
    $form = FormFacade::find('contact');
    if (! $form instanceof Form) {
        throw new RuntimeException('No [contact] form is stored.');
    }

    return $form->submissions()->count();
}

/**
 * The middleware core's submit route runs, in order, by class name.
 *
 * @return list<string>
 */
function submitRouteMiddleware(): array
{
    $router = app(Router::class);
    $route = $router->getRoutes()->getByName(ResolveSubmittedForm::ROUTE);
    if (! $route instanceof Route) {
        throw new RuntimeException('Core registered no form submit route.');
    }

    $middleware = [];
    foreach ($router->gatherRouteMiddleware($route) as $entry) {
        if (is_string($entry)) {
            $middleware[] = explode(':', $entry)[0];
        }
    }

    return $middleware;
}

/**
 * The position of a middleware in the submit route's stack.
 *
 * @throws RuntimeException When the route does not run it.
 */
function submitRoutePosition(string $middleware): int
{
    $position = array_search($middleware, submitRouteMiddleware(), true);
    if (! is_int($position)) {
        throw new RuntimeException("The submit route does not run [{$middleware}].");
    }

    return $position;
}

/**
 * The middleware in one of the router's groups.
 *
 * @return list<mixed>
 */
function routerGroup(string $group): array
{
    $middleware = app(Router::class)->getMiddlewareGroups()[$group] ?? null;
    if (! is_array($middleware)) {
        throw new RuntimeException("The router has no [{$group}] group.");
    }

    return array_values($middleware);
}

/**
 * Collect the warnings logged from here on.
 *
 * @return ArrayObject<int, string>
 */
function loggedWarnings(): ArrayObject
{
    /** @var ArrayObject<int, string> $warnings */
    $warnings = new ArrayObject;
    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($warnings): void {
        if ($event->level === 'warning') {
            $warnings[] = $event->message;
        }
    });

    return $warnings;
}

/**
 * The application's HTTP kernel, which Testbench builds from the framework's.
 */
function httpKernel(): Kernel
{
    return app(KernelContract::class);
}

it('accepts a submit from a site the form is assigned to', function (): void {
    submittableForm(['en']);

    submitFrom($this, 'http://localhost/contact')->assertRedirect();

    expect(storedSubmissions())->toBe(1);
});

it('answers 404 to a submit from a site the form is not assigned to, and stores nothing', function (): void {
    submittableForm(['en']);

    submitFrom($this, 'http://localhost/es/contacto')->assertNotFound();

    expect(storedSubmissions())->toBe(0);
});

it('accepts a submit to a form with no sites from every site, as core does', function (string $page): void {
    submittableForm(null);

    submitFrom($this, $page)->assertRedirect();

    expect(storedSubmissions())->toBe(1);
})->with([
    'English page' => ['http://localhost/contact'],
    'Spanish page' => ['http://localhost/es/contacto'],
]);

it('hands core the read-only view of the form for the submit site', function (): void {
    submittableForm(['en', 'es']);

    $submitted = null;
    Event::listen(FormSubmitted::class, function (FormSubmitted $event) use (&$submitted): void {
        $submitted = $event->submission->form();
    });

    submitFrom($this, 'http://localhost/es/contacto')->assertRedirect();

    expect($submitted)->toBeInstanceOf(SiteForm::class)
        ->and($submitted instanceof SiteForm ? $submitted->site() : null)->toBe('es');
});

it('runs after route binding and keeps the route forgery check', function (): void {
    expect(submitRoutePosition(ResolveSubmittedForm::class))->toBeGreaterThan(submitRoutePosition(SubstituteBindings::class))
        ->and(submitRouteMiddleware())->toContain(PreventRequestForgery::class);
});

it('stays after route binding even when pushed before it', function (): void {
    app(Router::class)->middlewareGroup('web', [ResolveSubmittedForm::class, ...routerGroup('web')]);

    expect(submitRoutePosition(ResolveSubmittedForm::class))->toBeGreaterThan(submitRoutePosition(SubstituteBindings::class));
});

it('keeps the gate on the group when the kernel later copies its groups to the router again', function (): void {
    // The kernel's group, priority and alias setters re-copy its groups onto the router.
    httpKernel()->prependToMiddlewarePriority(SubstituteBindings::class);

    expect(routerGroup('web'))->toContain(ResolveSubmittedForm::class);
});

it('keeps middleware other code pushed onto the group through the router alone', function (): void {
    $router = app(Router::class);
    $router->pushMiddlewareToGroup('web', 'msf-pushed-by-the-host');
    $router->middlewarePriority = [...$router->middlewarePriority, 'msf-priority-from-the-host'];

    SubmitGate::register($router, httpKernel());

    expect(routerGroup('web'))->toContain('msf-pushed-by-the-host')
        ->and(routerGroup('web'))->toContain(ResolveSubmittedForm::class)
        ->and(httpKernel()->getMiddlewareGroups()['web'] ?? [])->toContain('msf-pushed-by-the-host')
        ->and($router->middlewarePriority)->toContain('msf-priority-from-the-host');
});

it('warns when the front-end route middleware names no group, so nothing is gated', function (): void {
    $warnings = loggedWarnings();
    config(['statamic.routes.middleware' => ['not-a-group']]);

    SubmitGate::register(app(Router::class), httpKernel());

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0] ?? '')->toContain('could not gate form submits');
});

it('attaches to a group the router defines but the kernel does not', function (): void {
    $warnings = loggedWarnings();
    app(Router::class)->middlewareGroup('msf-router-only', []);
    config(['statamic.routes.middleware' => ['msf-router-only']]);

    SubmitGate::register(app(Router::class), httpKernel());

    expect(routerGroup('msf-router-only'))->toBe([ResolveSubmittedForm::class])
        ->and($warnings)->toHaveCount(0);
});

it('attaches through the router under a kernel that is not the framework one', function (): void {
    $warnings = loggedWarnings();
    app(Router::class)->middlewareGroup('msf-plain', []);
    config(['statamic.routes.middleware' => ['msf-plain']]);
    $kernel = new class implements KernelContract
    {
        public function bootstrap(): void {}

        public function handle($request): Response
        {
            return new Response;
        }

        public function terminate($request, $response): void {}

        public function getApplication(): Application
        {
            return app();
        }
    };

    SubmitGate::register(app(Router::class), $kernel);

    expect(routerGroup('msf-plain'))->toBe([ResolveSubmittedForm::class])
        ->and($warnings)->toHaveCount(0);
});

it('skips an entry that is not a group name and still attaches to the groups after it', function (): void {
    $warnings = loggedWarnings();
    app(Router::class)->middlewareGroup('msf-after', []);
    config(['statamic.routes.middleware' => [123, 'msf-after']]);

    SubmitGate::register(app(Router::class), httpKernel());

    expect(routerGroup('msf-after'))->toBe([ResolveSubmittedForm::class])
        ->and($warnings)->toHaveCount(0);
});

it('does not warn when it attaches to a group', function (): void {
    $warnings = loggedWarnings();

    SubmitGate::register(app(Router::class), httpKernel());

    expect($warnings)->toHaveCount(0);
});

it('passes a request with no route through', function (): void {
    $response = new ResolveSubmittedForm(new FormResolver, new RequestSite)
        ->handle(Request::create('http://localhost/es/'), fn (): Response => new Response('ok'));

    expect($response->getContent())->toBe('ok');
});

it('passes the submit route through, with a warning, when its form was never bound', function (): void {
    $warnings = loggedWarnings();
    $context = null;
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$context): void {
        $context = $event->context;
    });
    submittableForm(['en']);
    $request = Request::create('http://localhost/!/forms/contact', 'POST', server: ['HTTP_REFERER' => 'http://localhost/es/']);
    $route = new Route('POST', '!/forms/{form}', fn (): string => '');
    $route->name(ResolveSubmittedForm::ROUTE)->bind($request);
    $request->setRouteResolver(fn (): Route => $route);

    $response = new ResolveSubmittedForm(new FormResolver, new RequestSite)
        ->handle($request, fn (): Response => new Response('ok'));

    expect($response->getContent())->toBe('ok')
        ->and($route->parameter('form'))->toBe('contact')
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0] ?? '')->toContain('the form was not bound')
        ->and($context)->toBe(['form' => 'contact']);
});

it('passes any other route through without touching its form parameter', function (): void {
    $form = submittableForm(['en']);
    $request = Request::create('http://localhost/es/elsewhere');
    $route = new Route('GET', 'elsewhere', fn (): string => '');
    $route->name('elsewhere')->bind($request);
    $route->setParameter('form', $form);
    $request->setRouteResolver(fn (): Route => $route);

    new ResolveSubmittedForm(new FormResolver, new RequestSite)
        ->handle($request, fn (): Response => new Response('ok'));

    expect($route->parameter('form'))->toBe($form);
});

it("stores submissions in this test's own directory, never in shared storage", function (): void {
    submittableForm(null);
    submitFrom($this, 'http://localhost/contact')->assertRedirect();

    expect(File::isDirectory(formsDirectory().'/_submissions'))->toBeTrue();
});
