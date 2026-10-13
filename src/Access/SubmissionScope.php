<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Access;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use LogicException;
use Statamic\Contracts\Auth\User as UserContract;
use Statamic\Contracts\Forms\Submission;
use Statamic\Contracts\Forms\SubmissionQueryBuilder;
use Statamic\Events\FormDeleted;
use Statamic\Events\FormDeleting;
use Statamic\Facades\Form as FormFacade;
use Statamic\Facades\User;
use Statamic\Forms\Form;
use Statamic\Query\Builder;
use Statamic\Query\EloquentQueryBuilder;
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
 *
 * Deleting a form deletes its submissions through the same builder, so between
 * core's `FormDeleting` and `FormDeleted` events the request reads unscoped; a
 * form deleted from the Control Panel otherwise left behind the submissions its
 * deleter could not see, and they came back with a new form of the same handle.
 */
final class SubmissionScope
{
    /** The request attribute set while a form and its submissions are deleted. */
    public const string DELETING = 'multisite-forms.deleting-form';

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

        // Core dispatches FormDeleting without halting, so once it fires the delete
        // runs; the flag lives on the request so it cannot outlast it.
        Event::listen(FormDeleting::class, static function (): void {
            request()->attributes->set(self::DELETING, true);
        });
        Event::listen(FormDeleted::class, static function (): void {
            request()->attributes->remove(self::DELETING);
        });
    }

    /**
     * Add the scope to a new builder when the request calls for it.
     */
    public static function apply(SubmissionQueryBuilder $builder): SubmissionQueryBuilder
    {
        if (self::$reading || request()->attributes->get(self::DELETING) === true || ! self::isControlPanelRequest()) {
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
     * Whether the current request is for a Control Panel path.
     *
     * Core's `Statamic::isCpRoute()` compares the path with `statamic.cp.route` as
     * configured, while core registers the Control Panel's routes under that value
     * as a route prefix, which Laravel trims of slashes. So a route configured as
     * `/cp` serves the Control Panel at `cp` while `isCpRoute()` is false on every
     * request, which would leave every Control Panel read unscoped (#122). This
     * trims the configured value the same way. An empty route puts the Control
     * Panel's routes at the root, so every path is one of them.
     */
    public static function isControlPanelRequest(): bool
    {
        $route = config('statamic.cp.route');
        if (! config('statamic.cp.enabled') || ! is_string($route)) {
            return false;
        }

        $prefix = trim($route, '/');
        $path = trim(request()->path(), '/');

        return $prefix === '' || $path === $prefix || str_starts_with($path, $prefix.'/');
    }

    /**
     * Keep submissions of the given sites, and unattributed ones of the covered forms.
     *
     * When the user covers every form, an unattributed submission is kept outright
     * rather than by id, which saves reading every unattributed id on each request.
     *
     * @param  list<string>  $sites
     * @param  array{all: bool, handles: list<mixed>, others: list<mixed>}  $covered
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

        $unattributed = self::unattributedIds($covered['handles'], $covered['others']);

        $builder->where(static function (Builder|EloquentQueryBuilder $query) use ($sites, $unattributed): void {
            $query->whereIn('_site', $sites);
            $query->orWhereIn('id', $unattributed);
        });
    }

    /**
     * The handles of the forms whose every site the user can access, those of the
     * other forms, and whether the first is every form.
     *
     * Core's form getter does not type its handle, so the handles are only passed on
     * to a query, never read.
     *
     * @return array{all: bool, handles: list<mixed>, others: list<mixed>}
     */
    private static function coveredForms(UserContract $user): array
    {
        // Starting from false sends every user down the id rule, which keeps the same
        // submissions as the null rule when every form is covered, so TrueToFalse is
        // equivalent; scope() explains why the null rule is kept.
        // @pest-mutate-ignore: TrueToFalse
        $all = true;
        $handles = [];
        $others = [];

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
                $others[] = $form->handle();
            }
        }

        return ['all' => $all, 'handles' => $handles, 'others' => $others];
    }

    /**
     * The ids of the submissions with no `_site` in the given forms, less any id a
     * submission of one of the other forms also carries.
     *
     * An id is not unique across forms (it is the submission's file name, and a
     * copied or imported file can repeat one), and the id rule is not tied to a
     * form, so an id shared with a form the user does not cover is dropped.
     *
     * @param  list<mixed>  $forms
     * @param  list<mixed>  $others
     * @return array<mixed>
     */
    private static function unattributedIds(array $forms, array $others): array
    {
        // Without this return, an empty form list reads ids the exclusion below then
        // drops, since every form is one of the others; it only saves the reads, so
        // removing it is equivalent.
        // @pest-mutate-ignore: RemoveEarlyReturn
        if ($forms === []) {
            return [];
        }

        self::$reading = true;

        try {
            $ids = self::idsOf($forms, static function (Builder|EloquentQueryBuilder $query): void {
                $query->whereNull('_site');
            });

            if ($ids === [] || $others === []) {
                return $ids;
            }

            // Without the id condition the read returns every id of the other forms, which
            // drops the same ids; the condition only narrows the read, so removing it is
            // equivalent.
            // @pest-mutate-ignore: RemoveMethodCall
            $shared = self::idsOf($others, static function (Builder|EloquentQueryBuilder $query) use ($ids): void {
                $query->whereIn('id', $ids);
            });

            return array_filter($ids, static fn (mixed $id): bool => ! in_array($id, $shared, true));
        } finally {
            self::$reading = false;
        }
    }

    /**
     * The ids of the submissions of the given forms that a condition keeps, read unscoped.
     *
     * @param  list<mixed>  $forms
     * @param  callable(Builder|EloquentQueryBuilder): void  $condition
     * @return array<mixed>
     */
    private static function idsOf(array $forms, callable $condition): array
    {
        // apply() has already refused any other builder, and $reading leaves this read unscoped
        /** @var Builder|EloquentQueryBuilder $query */
        $query = app(SubmissionQueryBuilder::class);
        $query->whereIn('form', $forms);
        $condition($query);

        // Not pluck('id'): core's Stache builder plucks its own `form::id` keys,
        // which no `id` condition matches
        /** @var Collection<array-key, Submission> $found */
        $found = $query->get();

        return $found->map(static fn (Submission $submission): mixed => $submission->id())->all();
    }
}
