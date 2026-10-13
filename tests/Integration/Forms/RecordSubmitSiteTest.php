<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Event;
use Statamic\Contracts\Forms\Submission;
use Statamic\Events\FormSubmitted;
use Statamic\Facades\Form as FormFacade;
use Statamic\Facades\Stache;
use Statamic\Forms\Form;
use Statamic\Forms\SendEmails;
use Statamic\Stache\Stores\Store;
use UAMSWeb\MultisiteForms\Forms\RecordSubmitSite;
use UAMSWeb\MultisiteForms\Tests\TestCase;

/**
 * A submission made through core's submit route records the submit site under
 * `_site`, and a visitor cannot set it (#74).
 *
 * @command  vendor/bin/pest tests/Integration/Forms/RecordSubmitSiteTest.php
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
 * Save a global `feedback` form, storing its submissions unless told otherwise.
 */
function feedbackForm(bool $store = true): Form
{
    $form = new Form;
    $form->handle('feedback');
    $form->title('Feedback');
    $form->store($store);
    $form->save();

    return $form;
}

/**
 * Post to the `feedback` form from a page, with any extra request values.
 *
 * @param  array<string, mixed>  $values
 */
function postFeedback(TestCase $case, string $page, array $values = []): void
{
    $case->post('/!/forms/feedback', ['message' => 'hello', ...$values], ['referer' => $page])->assertRedirect();
}

/**
 * The `feedback` form's stored submissions, read back from storage.
 *
 * @return list<Submission>
 */
function storedFeedback(): array
{
    $form = FormFacade::find('feedback');
    if (! $form instanceof Form) {
        throw new RuntimeException('The feedback form is missing.');
    }

    return array_values(array_filter($form->submissions()->all(), fn (mixed $submission): bool => $submission instanceof Submission));
}

it('records the submit site on a stored submission', function (string $page, string $site): void {
    feedbackForm();

    postFeedback($this, $page);

    $submissions = storedFeedback();
    expect($submissions)->toHaveCount(1)
        ->and($submissions[0]->get(RecordSubmitSite::KEY))->toBe($site);
})->with([
    'from the en site' => ['http://localhost/contact', 'en'],
    'from the es site' => ['http://localhost/es/contacto', 'es'],
]);

it('ignores a _site value in the request', function (): void {
    feedbackForm();

    postFeedback($this, 'http://localhost/contact', ['_site' => 'es']);

    expect(storedFeedback()[0]->get(RecordSubmitSite::KEY))->toBe('en');
});

it('records the site on the submission the email job receives, when submissions are not stored', function (): void {
    Bus::fake();
    feedbackForm(store: false);

    postFeedback($this, 'http://localhost/es/contacto');

    Bus::assertDispatched(SendEmails::class, function (SendEmails $job): bool {
        $submission = new ReflectionProperty($job, 'submission')->getValue($job);

        return $submission instanceof Submission && $submission->get(RecordSubmitSite::KEY) === 'es';
    });
    expect(storedFeedback())->toBe([]);
});

it('records the site at the first save when an earlier listener halts FormSubmitted', function (): void {
    feedbackForm();
    Event::forget(FormSubmitted::class);
    Event::listen(FormSubmitted::class, fn (): bool => true);
    Event::listen(FormSubmitted::class, [RecordSubmitSite::class, 'submitted']);

    postFeedback($this, 'http://localhost/es/contacto');

    expect(storedFeedback()[0]->get(RecordSubmitSite::KEY))->toBe('es');
});

it('leaves a submission saved without the submit route unattributed', function (): void {
    $form = feedbackForm();

    $submission = $form->makeSubmission();
    $submission->data(['message' => 'hello']);
    $submission->save();

    $submissions = storedFeedback();
    expect($submissions)->toHaveCount(1)
        ->and($submissions[0]->data()->has(RecordSubmitSite::KEY))->toBeFalse();
});
