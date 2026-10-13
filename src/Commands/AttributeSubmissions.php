<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Statamic\Console\RunsInPlease;
use Statamic\Facades\Form as FormFacade;
use Statamic\Facades\FormSubmission;
use Statamic\Facades\Site;
use Statamic\Forms\Form;
use Statamic\Forms\Submission;
use Statamic\Query\Builder;
use Statamic\Query\EloquentQueryBuilder;
use Statamic\Sites\Site as SiteObject;
use UAMSWeb\MultisiteForms\Forms\Assignment;
use UAMSWeb\MultisiteForms\Forms\RecordSubmitSite;

/**
 * Records a site on a form's unattributed submissions (#81).
 *
 * A submission saved before the add-on recorded `_site`, or one saved outside the
 * submit route, has no site, and the Control Panel shows it only to a user who can
 * access every site its form is assigned to (#40). This command backfills such
 * submissions with one site, so editors of that site see them. A submission whose
 * file holds an empty `_site` is repaired the same way (#140). Submissions that
 * already record a site are left as they are.
 *
 * It reads through the submission query builder, so it works on whichever driver
 * stores submissions, and saves quietly, as core's own data migrations do: a
 * backfill is not a new submission, so no submission event or its listeners
 * should fire for it. No email is sent either way: core sends a form's email only
 * from its submit route. Each change is logged at `info` level with the submission's id.
 *
 * @command  php please multisite-forms:attribute contact en --dry-run
 */
#[Description('Record a site on the submissions of a form that have none')]
#[Signature('multisite-forms:attribute
        {form : The handle of the form whose submissions to attribute}
        {site : The handle of the site to record on them}
        {--dry-run : Report the submissions that would change, and change nothing}')]
final class AttributeSubmissions extends Command
{
    use RunsInPlease;

    /**
     * Attribute the form's unattributed submissions to the site.
     */
    public function handle(): int
    {
        $formHandle = $this->argumentText('form');
        $siteHandle = $this->argumentText('site');
        $form = self::formNamed($formHandle);
        $site = Site::get($siteHandle);

        if (! $form instanceof Form) {
            $this->components->error("There is no form [{$formHandle}].");

            return self::FAILURE;
        }

        if (! $site instanceof SiteObject) {
            $this->components->error("There is no site [{$siteHandle}].");

            return self::FAILURE;
        }

        // A submission recorded for a site its form does not cover is one the filter cannot offer (#58)
        if (! Assignment::covers($form, $site)) {
            $this->components->error("The form [{$formHandle}] is not assigned to the site [{$siteHandle}].");

            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run') === true;
        $count = 0;
        foreach ($this->unattributed($formHandle) as $submission) {
            $count++;
            // A string on the Stache and a number on the Eloquent driver; the contract declares none
            /** @var string|int|float $id */
            $id = $submission->id();
            if ($dryRun) {
                $this->line('Would attribute '.$id);

                continue;
            }

            $submission->set(RecordSubmitSite::KEY, $siteHandle);
            $submission->saveQuietly();
            $this->line('Attributed '.$id);
            Log::info('Multisite Forms attributed a submission to a site.', [
                'form' => $formHandle,
                'submission' => $id,
                'site' => $siteHandle,
            ]);
        }

        $this->components->info(sprintf(
            '%s %d %s of [%s] to [%s].',
            $dryRun ? 'Would attribute' : 'Attributed',
            $count,
            $count === 1 ? 'submission' : 'submissions',
            $formHandle,
            $siteHandle,
        ));

        return self::SUCCESS;
    }

    /**
     * The form with exactly this handle.
     *
     * Not `Form::find()`: on a filesystem that ignores case it finds `contact.yaml`
     * for `Contact` and returns a form with that handle, whose submissions the Stache
     * then saves through a store other than the form's own, leaving its index stale.
     */
    private static function formNamed(string $handle): ?Form
    {
        // The facade declares `mixed`; core's repository returns a collection of forms
        /** @var iterable<Form> $forms */
        $forms = FormFacade::all();
        foreach ($forms as $form) {
            if ($form->handle() === $handle) {
                return $form;
            }
        }

        return null;
    }

    /**
     * The form's submissions that record no site, or an empty one.
     *
     * Core drops an empty `_site` whenever it saves a submission, so an empty one
     * comes only from a file written around core, such as a direct import, and
     * saving it here writes the chosen site in its place (#130).
     *
     * @return iterable<Submission>
     */
    private function unattributed(string $form): iterable
    {
        // The facade declares no builder; core's and the Eloquent driver's both take these conditions
        /** @var Builder|EloquentQueryBuilder $query */
        $query = FormSubmission::query();
        $query->where('form', $form);
        $query->where(static function (Builder|EloquentQueryBuilder $query): void {
            $query->whereNull(RecordSubmitSite::KEY);
            $query->orWhere(RecordSubmitSite::KEY, '');
        });

        // Core's submissions and the Eloquent driver's both extend core's Submission class
        /** @var iterable<Submission> */
        return $query->get();
    }

    /**
     * A required argument's value, which the console always gives as a string.
     */
    private function argumentText(string $name): string
    {
        $value = $this->argument($name);

        // Unreachable: the console gives every required argument as a string, so
        // the empty fallback is there for PHPStan, which types argument() as mixed.
        // @pest-mutate-ignore: EmptyStringToNotEmpty
        return is_string($value) ? $value : '';
    }
}
