<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Access;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;
use LogicException;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Forms\Submission;
use Statamic\Contracts\Forms\SubmissionQueryBuilder;
use Statamic\Facades\Form as FormFacade;
use Statamic\Facades\User;
use Statamic\Forms\Form;
use Statamic\Query\Builder;
use Statamic\Query\EloquentQueryBuilder;
use Statamic\Statamic;
use UAMSWeb\MultisiteForms\Forms\Assignment;

/**
 * Scopes the submissions a Control Panel user reads to the sites they can access (#78).
 *
 * The scope decorates whichever builder is bound to core's submission query
 * contract, core's Stache builder or a driver's, through `app()->extend()`, so
 * the listing, detail view, delete, export and the forms index count are scoped
 * without replacing a controller or a builder class (#49). It applies only on a
 * Control Panel request by a signed-in user who is not a super user; the API, the
 * `{{ form:submissions }}` tag and console commands read every submission.
 *
 * A submission is kept when its `_site` is a site the user can access. One with no
 * `_site` is kept only when the user can access every site its form is assigned
 * to, or every site for a form with no sites (#40).
 *
 * The unattributed rule depends on the form, and core's Stache builder treats a
 * `form` condition as a restriction on the whole query, not as a condition that
 * can sit inside a nested group, so the scope never puts `form` in a group. When
 * the user covers some forms but not others, it reads the ids of the unattributed
 * submissions of the covered forms with a top-level query, and keeps those ids.
 */
final class SubmissionScope
{
    // SubmissionScopeTest fails when this default is true, shown by hand (#78), but a
    // property default is not an executable line, so the coverage driver links no
    // test to the mutant and it is never run.
    // @pest-mutate-ignore: FalseToTrue
    /** Set while the scope reads unattributed ids, so that read is not scoped itself. */
    private static bool $reading = false;

    /**
     * Decorate the bound submission query builder with the scope.
     */
    public static function register(Application $app): void
    {
        $app->extend(SubmissionQueryBuilder::class, static fn (SubmissionQueryBuilder $builder): SubmissionQueryBuilder => self::apply($builder));
    }

    /**
     * Add the scope to a new builder when the request calls for it.
     */
    public static function apply(SubmissionQueryBuilder $builder): SubmissionQueryBuilder
    {
        if (self::$reading || ! Statamic::isCpRoute()) {
            return $builder;
        }

        $user = User::current();
        if ($user === null || $user->isSuper()) {
            return $builder;
        }

        if (! $builder instanceof Builder && ! $builder instanceof EloquentQueryBuilder) {
            throw new LogicException('Cannot scope submissions by site: the bound submission query builder ['.$builder::class.'] extends neither of the core query builders.');
        }

        self::scope($builder, SiteAccess::accessible($user), self::coveredForms($user));

        return $builder;
    }

    /**
     * Keep submissions of the given sites, and unattributed ones of the covered forms.
     *
     * When the user covers every form, an unattributed submission is kept outright
     * rather than by id, which saves reading every unattributed id on each request.
     *
     * @param  list<string>  $sites
     * @param  array{all: bool, handles: list<mixed>}  $covered
     */
    private static function scope(Builder|EloquentQueryBuilder $builder, array $sites, array $covered): void
    {
        // Falling through from this branch adds the id rule over every form, which keeps
        // the same submissions; the branch only saves reading the ids, so removing its
        // return is equivalent.
        // @pest-mutate-ignore: RemoveEarlyReturn
        if ($covered['all']) {
            $builder->where(static function (Builder|EloquentQueryBuilder $query) use ($sites): void {
                $query->whereIn('_site', $sites);
                $query->orWhereNull('_site');
            });

            return;
        }

        $unattributed = self::unattributedIds($covered['handles']);

        $builder->where(static function (Builder|EloquentQueryBuilder $query) use ($sites, $unattributed): void {
            $query->whereIn('_site', $sites);
            $query->orWhereIn('id', $unattributed);
        });
    }

    /**
     * The handles of the forms whose every site the user can access, and whether that is every form.
     *
     * Core's form getter does not type its handle, so the handles are only passed on
     * to a query, never read.
     *
     * @return array{all: bool, handles: list<mixed>}
     */
    private static function coveredForms(UserContract $user): array
    {
        // Starting from false sends every user down the id rule, which keeps the same
        // submissions as the null rule when every form is covered, so TrueToFalse is
        // equivalent; scope() explains why the null rule is kept.
        // @pest-mutate-ignore: TrueToFalse
        $all = true;
        $handles = [];

        foreach (FormFacade::all() as $form) {
            // A form whose sites cannot be read is one the user is not known to cover
            if (! $form instanceof Form) {
                $all = false;

                continue;
            }

            $sites = Assignment::sites($form);
            if (SiteAccess::canAccessAll($user, $sites === [] ? SiteAccess::allHandles() : $sites)) {
                $handles[] = $form->handle();
            } else {
                $all = false;
            }
        }

        return ['all' => $all, 'handles' => $handles];
    }

    /**
     * The ids of the submissions with no `_site` in the given forms.
     *
     * @param  list<mixed>  $forms
     * @return array<mixed>
     */
    private static function unattributedIds(array $forms): array
    {
        if ($forms === []) {
            return [];
        }

        self::$reading = true;

        try {
            // apply() has already refused any other builder, and this read is unscoped
            /** @var Builder|EloquentQueryBuilder $query */
            $query = app(SubmissionQueryBuilder::class);
            $query->whereIn('form', $forms);
            $query->whereNull('_site');

            // Not pluck('id'): core's Stache builder plucks its own `form::id` keys,
            // which no `id` condition matches
            /** @var Collection<array-key, Submission> $found */
            $found = $query->get();

            return $found->map(static fn (Submission $submission): mixed => $submission->id())->all();
        } finally {
            self::$reading = false;
        }
    }
}
