<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Forms;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Statamic\Contracts\Forms\Submission;
use Statamic\Events\FormSubmitted;
use Statamic\Events\SubmissionCreating;
use UAMSWeb\MultisiteForms\Http\Middleware\ResolveSubmittedForm;
use UAMSWeb\MultisiteForms\Sites\RequestSite;

/**
 * Records the site a submission was made from, under the reserved `_site` key.
 *
 * It listens to `FormSubmitted`, which core dispatches from its submit route
 * after building the submission's data and before saving it or queuing its
 * emails. The site is the one core passes to the email job, from the shared
 * submit-site helper. A visitor cannot set the key: core treats request values
 * starting with `_` as parameters, and only blueprint fields become data.
 *
 * Core dispatches `FormSubmitted` halting on the first listener that returns
 * anything other than null, so a listener registered before this one can stop
 * it from running. `SubmissionCreating` is the backstop: on the submit route, a
 * new submission that reaches its first save without `_site` gets it then. A
 * submission saved any other way is left unattributed, as the spec defines.
 */
final class RecordSubmitSite
{
    /** The reserved data key the site is recorded under. */
    public const string KEY = '_site';

    public function __construct(private readonly RequestSite $requestSite, private readonly Request $request) {}

    /**
     * Record the submit site on a submission made through core's submit route.
     */
    public function submitted(FormSubmitted $event): void
    {
        $this->record($event->submission);
    }

    /**
     * Record it on the first save of a submission the route made, if nothing has.
     */
    public function creating(SubmissionCreating $event): void
    {
        $submission = $event->submission;
        // The event's property is untyped, so Larastan needs this guard; core always
        // passes a submission, so InstanceOfToTrue and the unreachable return
        // cannot be killed.
        // @pest-mutate-ignore: InstanceOfToTrue, RemoveEarlyReturn
        if (! $submission instanceof Submission) {
            return;
        }

        $route = $this->request->route();
        if (! $route instanceof Route || $route->getName() !== ResolveSubmittedForm::ROUTE || $submission->get(self::KEY) !== null) {
            return;
        }

        $this->record($submission);
    }

    private function record(Submission $submission): void
    {
        $submission->set(self::KEY, RequestSite::handle($this->requestSite->forSubmission()));
    }
}
