<?php

declare(strict_types=1);

/**
 * The README's stated requirements and license must follow `composer.json`, which is what a
 * Packagist or Marketplace visitor and Composer itself read (#25).
 *
 * @command  vendor/bin/pest tests/Unit/ReadmeTest.php
 */

/**
 * The parsed `composer.json`.
 *
 * @return array{license: string, require: array<string, string>}
 */
function readmeComposerManifest(): array
{
    /** @var array{license: string, require: array<string, string>} */
    return json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
}

/** The README's text. */
function readmeText(): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/README.md');
}

it('states the PHP floor that composer.json requires', function (): void {
    $floor = ltrim(readmeComposerManifest()['require']['php'], '^>=~ ');

    expect(readmeText())->toContain('PHP '.$floor.' or later');
});

it('states the statamic/cms floor that composer.json requires', function (): void {
    $floor = ltrim(readmeComposerManifest()['require']['statamic/cms'], '^>=~ ');

    expect(readmeText())->toContain($floor.' or later');
});

it('names the license that composer.json declares', function (): void {
    expect(readmeText())->toContain(readmeComposerManifest()['license'].'. See [`LICENSE`](LICENSE)');
});

it('requires the package by the name composer.json gives it', function (): void {
    /** @var array{name: string} $manifest */
    $manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2).'/composer.json'), true, flags: JSON_THROW_ON_ERROR);

    expect(readmeText())->toContain('composer require '.$manifest['name']);
});

it('links the files it points readers to, and they exist', function (): void {
    foreach (['LICENSE', 'AGENTS.md', 'docs/DEVELOPMENT.md'] as $path) {
        expect(readmeText())->toContain('('.$path.')')
            ->and(dirname(__DIR__, 2).'/'.$path)->toBeFile();
    }
});
