<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Statamic\Events\FormBlueprintFound;
use Statamic\Exceptions\NotFoundHttpException;
use Statamic\Facades\Blink;
use Statamic\Facades\Site;
use Statamic\Fields\Blueprint;
use Statamic\Forms\Form;
use Statamic\Forms\RenderableField;
use Statamic\Forms\Tags as CoreFormTags;
use Statamic\Tags\Loader;
use UAMSWeb\MultisiteForms\Forms\NotAssigned;
use UAMSWeb\MultisiteForms\Forms\SiteForm;
use UAMSWeb\MultisiteForms\Tags\FormTags;

/**
 * The `{{ form }}` tags render a form for the page's site, and follow the
 * `not_assigned` setting for a form not assigned to it (#73).
 *
 * @command  vendor/bin/pest tests/Integration/Tags/FormTagsTest.php
 */
beforeEach(function (): void {
    useTwoSites();
    useTemporaryFormsDirectory();
    Site::setCurrent('en');
});

afterEach(function (): void {
    removeFormsDirectory();
});

/**
 * Save a `contact` form assigned to the given sites, or global when given null.
 *
 * @param  array<array-key, mixed>|null  $sites
 */
function taggedForm(?array $sites): Form
{
    $form = new Form;
    $form->handle('contact');
    $form->title('Contact');
    $form->honeypot('trap');
    if ($sites !== null) {
        $form->set('sites', $sites);
    }

    $form->save();

    return $form;
}

/**
 * Load the `form` tag the way Antlers does, for one of its methods.
 *
 * @param  class-string  $expected  the tag class the handle must resolve to
 * @param  array<string, mixed>  $params
 * @param  array<string, mixed>  $context
 */
function formTag(string $method, array $params = ['in' => 'contact'], array $context = [], string $expected = FormTags::class): CoreFormTags
{
    $tag = app(Loader::class)->load('form', [
        'parser' => null,
        'params' => $params,
        'content' => '',
        'context' => $context,
        'tag' => "form:{$method}",
        'tag_method' => $method,
    ]);

    if (! $tag instanceof $expected || ! $tag instanceof CoreFormTags) {
        throw new RuntimeException("The form tag did not load as [{$expected}].");
    }

    return $tag;
}

/**
 * Core's own form tags, loaded directly, for comparison.
 *
 * @param  array<string, mixed>  $params
 */
function coreFormTag(array $params = ['in' => 'contact']): CoreFormTags
{
    $tag = new CoreFormTags;
    $tag->setProperties([
        'parser' => null,
        'params' => $params,
        'content' => '',
        'context' => [],
        'tag' => 'form:create',
        'tag_method' => 'create',
    ]);

    return $tag;
}

/**
 * The tag's data with each renderable field replaced by the HTML it renders, which
 * is what a template outputs. The field objects themselves differ by design: each
 * carries the site's view of the form rather than the base form.
 *
 * @return array<array-key, mixed>
 */
function rendered(mixed $data): array
{
    $walk = function (mixed $value) use (&$walk): mixed {
        if (is_array($value)) {
            return array_map($walk, $value);
        }

        return $value instanceof RenderableField ? $value->toHtml() : $value;
    };

    $walked = $walk($data);
    if (! is_array($walked)) {
        throw new RuntimeException('The form tag did not return data.');
    }

    return $walked;
}

/**
 * Collect the log records written from here on.
 *
 * @return ArrayObject<int, MessageLogged>
 */
function loggedRecords(): ArrayObject
{
    /** @var ArrayObject<int, MessageLogged> $records */
    $records = new ArrayObject;
    Event::listen(MessageLogged::class, function (MessageLogged $event) use ($records): void {
        $records[] = $event;
    });

    return $records;
}

it("replaces core's form tags under the form handle", function (): void {
    expect(formTag('create'))->toBeInstanceOf(FormTags::class);
});

it("reads the not_assigned setting from the add-on's config, defaulting to empty", function (): void {
    expect(config(NotAssigned::CONFIG_KEY))->toBe('empty')
        ->and(NotAssigned::fromConfig())->toBe(NotAssigned::Empty);
});

it('renders an assigned form, or one with no sites, as core renders it', function (?array $sites): void {
    taggedForm($sites);
    Event::listen(FormBlueprintFound::class, function (FormBlueprintFound $event): void {
        $blueprint = $event->blueprint;
        if (! $blueprint instanceof Blueprint) {
            throw new RuntimeException('The form blueprint event carried no blueprint.');
        }

        $blueprint->setContents(['tabs' => ['main' => ['sections' => [['fields' => [
            ['handle' => 'name', 'field' => ['type' => 'text', 'validate' => 'required']],
        ]]]]]]);
    });
    $params = ['in' => 'contact', 'js' => 'alpine'];

    $ours = formTag('create', $params)->create();
    Blink::flush();
    $core = coreFormTag($params)->create();

    // With no parser to render content, core's create returns the data a template would receive.
    expect(rendered($ours))->toEqual(rendered($core))
        ->and(data_get(rendered($ours), 'fields.0.field'))->toContain('name="name"')
        ->and(data_get($ours, 'honeypot'))->toBe('trap')
        ->and(data_get($ours, 'fields.0.handle'))->toBe('name')
        ->and(data_get($ours, 'js_driver'))->toBe('alpine');
})->with([
    "assigned to the page's site" => [['en']],
    'no sites' => [null],
]);

it("hands core the read-only view of the form for the page's site", function (): void {
    taggedForm(['en', 'es']);
    Site::setCurrent('es');

    $tag = formTag('create');
    $form = new ReflectionMethod($tag, 'form')->invoke($tag);

    expect($form)->toBeInstanceOf(SiteForm::class)
        ->and($form instanceof SiteForm ? $form->site() : null)->toBe('es');
});

it('renders nothing from every form tag for an unassigned form under empty', function (string $method, mixed $nothing): void {
    taggedForm(['en']);
    Site::setCurrent('es');

    expect(formTag($method)->{$method}())->toBe($nothing);
})->with([
    'create' => ['create', ''],
    'errors' => ['errors', false],
    'success' => ['success', false],
    'submission' => ['submission', null],
    'submissions' => ['submissions', []],
]);

it('returns the submission core flashed for an assigned form, and nothing before a submit', function (): void {
    $form = taggedForm(['en']);

    expect(formTag('submission')->submission())->toBeNull();

    $submission = $form->makeSubmission();

    session()->put('form.contact.success', 'Submission successful.');
    session()->put('submission', $submission);

    // Core exposes the blueprint's fields plus `id` and `date`; this form has no fields.
    expect(data_get(formTag('submission')->submission(), 'id'))->toBe($submission->id());
});

it('renders nothing through the wildcard form of the tag', function (): void {
    taggedForm(['en']);
    Site::setCurrent('es');

    $tag = formTag('contact', params: []);

    expect($tag->__call('contact', []))->toBe('');
});

it('logs one warning naming the form and the site, however often the form is rendered', function (): void {
    taggedForm(['en']);
    Site::setCurrent('es');
    $records = loggedRecords();

    formTag('create')->create();
    formTag('errors')->errors();

    $warnings = array_values(array_filter($records->getArrayCopy(), fn (MessageLogged $record): bool => $record->level === 'warning'));

    expect($warnings)->toHaveCount(1)
        ->and($warnings[0]->message)->toContain('not assigned')
        ->and($warnings[0]->context)->toBe(['form' => 'contact', 'site' => 'es']);
});

it('aborts with 404 for an unassigned form under 404', function (int|string $setting): void {
    config([NotAssigned::CONFIG_KEY => $setting]);
    taggedForm(['en']);
    Site::setCurrent('es');

    formTag('create')->create();
})->with([
    'the string 404' => ['404'],
    'the integer 404' => [404],
])->throws(NotFoundHttpException::class, 'Form [contact] is not assigned to site [es].');

it('renders nothing rather than aborting again while the 404 page renders the same form', function (): void {
    config([NotAssigned::CONFIG_KEY => '404']);
    taggedForm(['en']);
    Site::setCurrent('es');

    $other = new Form;
    $other->handle('newsletter');
    $other->set('sites', ['en']);
    $other->save();

    expect(fn (): mixed => formTag('create')->create())->toThrow(NotFoundHttpException::class)
        ->and(formTag('create')->create())->toBe('')
        ->and(formTag('create', ['in' => 'newsletter'])->create())->toBe('');
});

it('leaves form:fields to core, which renders only what form:create hands it', function (): void {
    expect(new ReflectionMethod(FormTags::class, 'fields')->getDeclaringClass()->getName())->toBe(CoreFormTags::class);
});

it('gates a form passed in from context, which core would put in its cache directly', function (): void {
    $form = taggedForm(['en']);
    Site::setCurrent('es');

    expect(formTag('create', params: [], context: ['form' => $form])->create())->toBe('');
});

it('gates the base form when the cache already holds a view for another site', function (): void {
    $form = taggedForm(['en']);
    Blink::put('form-contact', SiteForm::of($form, 'en'));
    Site::setCurrent('es');

    expect(formTag('create')->create())->toBe('');
});

it('leaves a missing form to core, which reports it', function (): void {
    formTag('create', params: ['in' => 'nope'])->create();
})->throws(Exception::class, 'Form with handle [nope] cannot be found.');

it('refuses a not_assigned value that is not a setting', function (mixed $value): void {
    config([NotAssigned::CONFIG_KEY => $value]);

    NotAssigned::fromConfig();
})->with([
    'an unknown word' => ['hide'],
    'a list' => [['empty']],
    'null' => [null],
])->throws(InvalidArgumentException::class, 'statamic-multisite-forms.not_assigned');
