<?php

declare(strict_types=1);

/**
 * `composer.json` carries every field the submission checklist lists as a prerequisite for a
 * public package (#26). The test fails when one is dropped; it does not submit anything.
 *
 * @command  vendor/bin/pest tests/Unit/SubmissionReadinessTest.php
 */

/**
 * The parsed `composer.json`.
 *
 * @return array<string, mixed>
 */
function submissionManifest(): array
{
    /** @var array<string, mixed> */
    return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
}

it('declares every top-level field a public package needs', function (string $field): void {
    expect(submissionManifest())->toHaveKey($field);
    expect(submissionManifest()[$field])->not->toBeEmpty();
})->with(['name', 'description', 'keywords', 'type', 'license', 'authors', 'support', 'autoload']);

it('is a statamic-addon under the uams-web vendor', function (): void {
    $manifest = submissionManifest();

    expect($manifest['type'])->toBe('statamic-addon')
        ->and($manifest['name'])->toStartWith('uams-web/');
});

it('names and describes itself for the Control Panel', function (): void {
    /** @var array{statamic?: array{name?: string, description?: string}, laravel?: array{providers?: list<string>}} $extra */
    $extra = submissionManifest()['extra'];

    expect($extra['statamic']['name'] ?? '')->not->toBeEmpty()
        ->and($extra['statamic']['description'] ?? '')->not->toBeEmpty()
        ->and($extra['laravel']['providers'] ?? [])->not->toBeEmpty();
});

it('declares no editions, as decided in #17', function (): void {
    /** @var array{statamic: array<string, mixed>} $extra */
    $extra = submissionManifest()['extra'];

    expect($extra['statamic'])->not->toHaveKey('editions');
});

it('ships the checklist the readiness fields come from', function (): void {
    expect(dirname(__DIR__, 2).'/docs/SUBMISSION.md')->toBeFile();
});
