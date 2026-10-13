<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use Illuminate\Testing\PendingCommand;
use Statamic\Console\RunsInPlease;
use Statamic\Events\SubmissionCreated;
use Statamic\Events\SubmissionSaved;
use Statamic\Events\SubmissionSaving;
use Statamic\Facades\Form as FormFacade;
use Statamic\Facades\FormSubmission;
use Statamic\Facades\Stache;
use Statamic\Forms\Form;
use Statamic\Forms\Submission;
use Statamic\Stache\Stores\Store;
use UAMSWeb\MultisiteForms\Commands\AttributeSubmissions;
use UAMSWeb\MultisiteForms\Tests\TestCase;

/**
 * `multisite-forms:attribute {form} {site}` records a site on a form's
 * submissions that have none, and touches nothing else (#81).
 *
 * @command  vendor/bin/pest tests/Integration/Commands/AttributeSubmissionsTest.php
 */

/** The forms every test stores, by handle, with the sites each lists. */
const ATTRIBUTE_FORMS = [
    'shared' => ['en', 'es'],
    'english' => ['en'],
    'everywhere' => [],
];

beforeEach(function (): void {
    useTwoSites();

    // Every info line logged during the test, as [message, context]
    $this->logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event): void {
        if ($event->level === 'info') {
            $this->logged[] = [$event->message, $event->context];
        }
    });

    $this->directory = useTemporaryFormsDirectory();

    $store = Stache::store('form-submissions');
    if (! $store instanceof Store) {
        throw new RuntimeException('The Stache has no form submissions store.');
    }

    $store->directory($this->directory.'/_submissions');

    foreach (ATTRIBUTE_FORMS as $handle => $sites) {
        $form = FormFacade::make($handle);
        if (! $form instanceof Form) {
            throw new RuntimeException('The forms repository did not make a form.');
        }

        $form->title(ucfirst($handle));
        if ($sites !== []) {
            $form->set('sites', $sites);
        }

        $form->save();

        // One submission per site and two with no site, named after the site. Core's
        // default id has 0.1ms resolution, so two saved that close would share one.
        foreach (['en', 'es', null, null] as $index => $site) {
            $submission = $form->makeSubmission();
            $submission->id("{$handle}-{$index}");
            $submission->set('name', ($site ?? 'none').$index);
            if ($site !== null) {
                $submission->set('_site', $site);
            }

            $submission->save();
        }
    }
});

afterEach(function (): void {
    removeFormsDirectory();
});

/**
 * The attribute command, run with the given arguments once its expectations are set.
 *
 * @param  array<string, mixed>  $arguments
 */
function attribute(TestCase $case, array $arguments): PendingCommand
{
    $command = $case->artisan('multisite-forms:attribute', $arguments);
    if (! $command instanceof PendingCommand) {
        throw new RuntimeException('The command ran before its expectations were set.');
    }

    return $command;
}

/**
 * A form's stored submissions.
 *
 * @return iterable<Submission>
 */
function storedSubmissionsOf(string $form): iterable
{
    // The facade declares `mixed`; core returns a collection of its submissions
    /** @var iterable<Submission> */
    return FormSubmission::whereForm($form);
}

/**
 * Each submission of a form by name, with the site it records, or null for none.
 *
 * @return array<string, mixed>
 */
function recordedSites(string $form): array
{
    $sites = [];
    foreach (storedSubmissionsOf($form) as $submission) {
        $name = $submission->get('name');
        if (! is_string($name)) {
            throw new RuntimeException('A submission has no name.');
        }

        $sites[$name] = $submission->get('_site');
    }

    ksort($sites);

    return $sites;
}

/**
 * Every stored file under a directory, by path, with a hash of its contents.
 *
 * @return array<string, string>
 */
function storedFiles(string $directory): array
{
    $files = [];
    foreach (File::allFiles($directory) as $file) {
        $files[$file->getPathname()] = (string) md5_file($file->getPathname());
    }

    ksort($files);

    return $files;
}

/**
 * The ids of a form's submissions that record no site.
 *
 * @return list<string>
 */
function unattributedIds(string $form): array
{
    $ids = [];
    foreach (storedSubmissionsOf($form) as $submission) {
        $id = $submission->id();
        if ($submission->get('_site') === null && is_string($id)) {
            $ids[] = $id;
        }
    }

    sort($ids);

    return $ids;
}

it('records the site on the submissions that have none, and leaves the rest', function (): void {
    attribute($this, ['form' => 'shared', 'site' => 'es'])
        ->expectsOutputToContain('Attributed 2 submissions of [shared] to [es].')
        ->assertSuccessful();

    expect(recordedSites('shared'))->toBe(['en0' => 'en', 'es1' => 'es', 'none2' => 'es', 'none3' => 'es']);
});

it("leaves other forms' submissions alone", function (): void {
    attribute($this, ['form' => 'shared', 'site' => 'en'])->assertSuccessful();

    expect(recordedSites('english'))->toBe(['en0' => 'en', 'es1' => 'es', 'none2' => null, 'none3' => null])
        ->and(recordedSites('everywhere'))->toBe(['en0' => 'en', 'es1' => 'es', 'none2' => null, 'none3' => null]);
});

it('attributes the submissions of a form with no sites to any site', function (): void {
    attribute($this, ['form' => 'everywhere', 'site' => 'es'])->assertSuccessful();

    expect(recordedSites('everywhere'))->toBe(['en0' => 'en', 'es1' => 'es', 'none2' => 'es', 'none3' => 'es']);
});

it('stores the site so a later read finds it', function (): void {
    attribute($this, ['form' => 'shared', 'site' => 'en'])->assertSuccessful();

    Stache::clear();

    expect(recordedSites('shared'))->toBe(['en0' => 'en', 'es1' => 'es', 'none2' => 'en', 'none3' => 'en']);
});

it('names each submission it changes, and logs each with its id', function (): void {
    $ids = unattributedIds('shared');
    $command = attribute($this, ['form' => 'shared', 'site' => 'es']);
    foreach ($ids as $id) {
        $command->expectsOutputToContain("Attributed {$id}");
    }

    $command->assertSuccessful();
    $command->run();

    $expected = array_map(static fn (string $id): array => ['Multisite Forms attributed a submission to a site.', [
        'form' => 'shared',
        'submission' => $id,
        'site' => 'es',
    ]], $ids);

    expect($ids)->toHaveCount(2)
        ->and($this->logged)->toEqualCanonicalizing($expected);
});

it('reports what a dry run would change, and writes and logs nothing', function (): void {
    $ids = unattributedIds('shared');
    $before = storedFiles($this->directory);

    $command = attribute($this, ['form' => 'shared', 'site' => 'es', '--dry-run' => true]);
    foreach ($ids as $id) {
        $command->expectsOutputToContain("Would attribute {$id}");
    }

    $command->expectsOutputToContain('Would attribute 2 submissions of [shared] to [es].')->assertSuccessful();
    $command->run();

    Stache::clear();

    expect(storedFiles($this->directory))->toBe($before)
        ->and($before)->not->toBe([])
        ->and(recordedSites('shared'))->toBe(['en0' => 'en', 'es1' => 'es', 'none2' => null, 'none3' => null])
        ->and($this->logged)->toBe([]);
});

it('saves quietly, so no submission event fires', function (): void {
    Event::fake([SubmissionCreated::class, SubmissionSaving::class, SubmissionSaved::class]);

    attribute($this, ['form' => 'shared', 'site' => 'es'])->assertSuccessful();

    expect(recordedSites('shared'))->toHaveKey('none2', 'es');
    Event::assertNotDispatched(SubmissionCreated::class);
    Event::assertNotDispatched(SubmissionSaving::class);
    Event::assertNotDispatched(SubmissionSaved::class);
});

it('counts one submission, and none', function (): void {
    attribute($this, ['form' => 'shared', 'site' => 'es'])->assertSuccessful();

    attribute($this, ['form' => 'shared', 'site' => 'es'])
        ->expectsOutputToContain('Attributed 0 submissions of [shared] to [es].')
        ->assertSuccessful();

    $form = FormFacade::find('shared');
    $submission = $form->makeSubmission();
    $submission->set('name', 'late');
    $submission->save();

    attribute($this, ['form' => 'shared', 'site' => 'en'])
        ->expectsOutputToContain('Attributed 1 submission of [shared] to [en].')
        ->assertSuccessful();
});

it('refuses an unknown form, an unknown site, or a site the form is not assigned to, and changes nothing', function (string $form, string $site, string $message): void {
    $before = storedFiles($this->directory);

    attribute($this, ['form' => $form, 'site' => $site])
        ->expectsOutputToContain($message)
        ->assertFailed();

    expect(storedFiles($this->directory))->toBe($before);
})->with([
    'an unknown form' => ['missing', 'en', 'There is no form [missing].'],
    'a form handle in another case' => ['Shared', 'en', 'There is no form [Shared].'],
    'an unknown site' => ['shared', 'fr', 'There is no site [fr].'],
    'a site the form is not assigned to' => ['english', 'es', 'The form [english] is not assigned to the site [es].'],
]);

it('is registered under its own name for artisan and please', function (): void {
    expect(Artisan::all())->toHaveKey('multisite-forms:attribute')
        ->and(Artisan::all()['multisite-forms:attribute'])->toBeInstanceOf(AttributeSubmissions::class)
        ->and(class_uses(AttributeSubmissions::class))->toHaveKey(RunsInPlease::class);
});
