# statamic-multisite-forms

Statamic add-on that makes forms per-site in a multisite install: site assignment, per-site overrides, and site-separated submissions in flat files.

## Development

```sh
composer install
composer ci    # Pint, Rector dry run, Larastan, Pest (Unit and Integration)
```

Requires PHP 8.4 or later and `statamic/cms` 6.35 or later. The project conventions are in [`AGENTS.md`](AGENTS.md).
