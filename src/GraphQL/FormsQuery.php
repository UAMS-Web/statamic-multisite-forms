<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\GraphQL;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Statamic\Facades\GraphQL;
use Statamic\Forms\Form;
use Statamic\GraphQL\Queries\FormsQuery as CoreFormsQuery;
use UAMSWeb\MultisiteForms\Access\SiteAccess;
use UAMSWeb\MultisiteForms\Forms\Assignment;

/**
 * The GraphQL `forms` query, with an optional `site` argument (#80).
 *
 * `forms(site: "es")` lists only the forms assigned to `es`, including forms with
 * no sites, which belong to every site. Without the argument, or with it null, the
 * query is core's. A `site` that names no configured site fails validation, so a
 * typo answers an error rather than an empty list.
 *
 * The GraphQL library builds each query class through the container, so binding
 * this subclass in place of core's adds the argument without changing the schema
 * Statamic registers.
 */
final class FormsQuery extends CoreFormsQuery
{
    /**
     * Use this query wherever core's GraphQL forms query is built.
     */
    public static function register(Application $app): void
    {
        $app->bind(CoreFormsQuery::class, self::class);
    }

    /**
     * Core's arguments, of which there are none, and `site`.
     *
     * @return array<string, mixed>
     */
    #[\Override]
    public function args(): array
    {
        return [
            'site' => GraphQL::string(),
        ];
    }

    /**
     * Core's forms, narrowed to the requested site.
     *
     * @param  mixed  $root
     * @param  array<string, mixed>  $args
     * @return Collection<array-key, Form>
     */
    #[\Override]
    public function resolve($root, $args): Collection
    {
        /** @var Collection<array-key, Form> $forms */
        $forms = parent::resolve($root, $args);

        $site = $args['site'] ?? null;
        if (! is_string($site)) {
            return $forms;
        }

        return $forms->filter(static fn (Form $form): bool => Assignment::covers($form, $site))->values();
    }

    /**
     * `site`, when given, must name a configured site.
     *
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    #[\Override]
    protected function rules(array $args = []): array
    {
        return [
            'site' => ['nullable', 'string', Rule::in(SiteAccess::allHandles())],
        ];
    }
}
