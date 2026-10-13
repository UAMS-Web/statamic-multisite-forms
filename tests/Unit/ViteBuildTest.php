<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use UAMSWeb\MultisiteForms\ServiceProvider;

/**
 * The Control Panel build: the provider's `$vite` property, `vite.config.js`, the entry sources
 * and the committed manifest must all describe the same files (#15).
 *
 * @command  vendor/bin/pest tests/Unit/ViteBuildTest.php
 */

/**
 * The provider's `$vite` property, which is protected and has no accessor. A subclass reads it
 * so the property's declared array shape carries through to the caller.
 *
 * @return array{input: list<string>, publicDirectory: string}
 */
function viteBuildProperty(): array
{
    $probe = new class(new Application(viteBuildRoot())) extends ServiceProvider
    {
        /** @return array{input: list<string>, publicDirectory: string} */
        public function vite(): array
        {
            return $this->vite;
        }
    };

    return $probe->vite();
}

/** The repository root. */
function viteBuildRoot(): string
{
    return dirname(__DIR__, 2);
}

it('points the provider at entry files that exist', function (): void {
    $vite = viteBuildProperty();

    expect($vite['input'])->not->toBeEmpty();

    foreach ($vite['input'] as $entry) {
        expect(viteBuildRoot().'/'.$entry)->toBeFile();
    }
});

it('keeps vite.config.js and the provider on the same inputs and output directory', function (): void {
    $vite = viteBuildProperty();
    $config = (string) file_get_contents(viteBuildRoot().'/vite.config.js');

    foreach ($vite['input'] as $entry) {
        expect($config)->toContain("'".$entry."'");
    }

    expect($config)->toContain("publicDirectory: '".$vite['publicDirectory']."'");
});

it('lists in the provider every input vite.config.js builds and every entry the manifest holds', function (): void {
    $vite = viteBuildProperty();
    $provided = $vite['input'];
    sort($provided);

    // The checks above iterate the provider's own list, so they cannot see an entry removed from it.
    $config = (string) file_get_contents(viteBuildRoot().'/vite.config.js');
    if (preg_match('/input:\s*\[([^\]]*)\]/', $config, $match) !== 1) {
        throw new RuntimeException('vite.config.js has no input list.');
    }

    preg_match_all("/'([^']+)'/", $match[1], $quoted);
    $configured = $quoted[1];
    sort($configured);

    /** @var array<string, array{isEntry?: bool}> $manifest */
    $manifest = json_decode((string) file_get_contents(viteBuildRoot().'/'.$vite['publicDirectory'].'/build/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    $entries = array_keys(array_filter($manifest, fn (array $chunk): bool => $chunk['isEntry'] ?? false));
    sort($entries);

    expect($configured)->toBe(['resources/css/cp.css', 'resources/js/cp.js'])
        ->and($provided)->toBe($configured)
        ->and($entries)->toBe($configured);
});

it('commits a manifest that lists every entry with a built file on disk', function (): void {
    $vite = viteBuildProperty();
    $manifestPath = viteBuildRoot().'/'.$vite['publicDirectory'].'/build/manifest.json';

    expect($manifestPath)->toBeFile();

    /** @var array<string, array{file: string, isEntry?: bool}> $manifest */
    $manifest = json_decode((string) file_get_contents($manifestPath), true, flags: JSON_THROW_ON_ERROR);

    foreach ($vite['input'] as $entry) {
        expect($manifest)->toHaveKey($entry);
        expect($manifest[$entry]['isEntry'] ?? false)->toBeTrue();
        expect(viteBuildRoot().'/'.$vite['publicDirectory'].'/build/'.$manifest[$entry]['file'])->toBeFile();
    }
});
