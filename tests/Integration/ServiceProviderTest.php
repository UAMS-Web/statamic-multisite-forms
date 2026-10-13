<?php

declare(strict_types=1);

use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use UAMSWeb\MultisiteForms\Forms\NotAssigned;
use UAMSWeb\MultisiteForms\ServiceProvider;

it('registers its service provider with the application', function (): void {
    expect(app()->getProvider(ServiceProvider::class))->toBeInstanceOf(ServiceProvider::class);
});

it('publishes its config file under the tag the README names', function (): void {
    $paths = ServiceProvider::pathsToPublish(null, 'statamic-multisite-forms-config');

    expect(array_map(realpath(...), array_keys($paths)))->toBe([realpath(__DIR__.'/../../config/statamic-multisite-forms.php')])
        ->and(array_values($paths))->toBe([config_path('statamic-multisite-forms.php')]);
});

/**
 * Boot the add-on again with the given not_assigned value, and return the messages
 * logged at error level while it booted.
 *
 * @return list<string>
 */
function errorsLoggedBooting(mixed $notAssigned): array
{
    config([NotAssigned::CONFIG_KEY => $notAssigned]);

    /** @var list<string> $errors */
    $errors = [];
    Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$errors): void {
        if ($event->level === 'error') {
            $errors[] = $event->message;
        }
    });

    $provider = app()->getProvider(ServiceProvider::class);
    if (! $provider instanceof ServiceProvider) {
        throw new RuntimeException('The add-on provider is not registered.');
    }

    $provider->bootAddon();

    return $errors;
}

it('logs an invalid not_assigned setting when the add-on boots (#90)', function (mixed $value, string $shown): void {
    $errors = errorsLoggedBooting($value);

    expect($errors)->toHaveCount(1)
        ->and($errors[0])->toContain(NotAssigned::CONFIG_KEY)
        ->and($errors[0])->toContain($shown);
})->with([
    'an unknown word' => ['hide', "'hide'"],
    'null, as an unset env() gives' => [null, 'NULL'],
    'a list' => [['empty'], 'array'],
]);

it('logs nothing at boot for a valid not_assigned setting', function (int|string $value): void {
    expect(errorsLoggedBooting($value))->toBe([]);
})->with([
    'empty' => ['empty'],
    'the string 404' => ['404'],
    'the integer 404' => [404],
]);
