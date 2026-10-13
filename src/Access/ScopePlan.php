<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Access;

/**
 * What the submission scope applies for one user in one request (#123).
 *
 * @internal Held on the request by SubmissionScope, which drops it on any write that could change it.
 */
final readonly class ScopePlan
{
    /**
     * @param  mixed  $user  the user's auth identifier
     * @param  list<string>  $sites  the sites the user can access
     * @param  bool  $all  whether the user covers every form
     * @param  array<mixed>  $ids  the unattributed submission ids the user may read; empty when $all
     */
    public function __construct(
        public mixed $user,
        public array $sites,
        public bool $all,
        public array $ids,
    ) {}
}
