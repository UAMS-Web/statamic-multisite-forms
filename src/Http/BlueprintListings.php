<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Http;

use Illuminate\Http\Request;
use Illuminate\Routing\Events\PreparingResponse;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Inertia\Response as InertiaResponse;
use Statamic\Http\Controllers\CP\Fields\BlueprintController;
use UAMSWeb\MultisiteForms\Access\RefusedFormEditors;

/**
 * Keeps forms the user may not open out of the blueprints index and the command palette (#133).
 *
 * Core's blueprints index lists every form's blueprint, with its title and a link
 * to its fields editor, in the page's `forms` prop (`BlueprintController::forms()`),
 * and the command palette lists every form's blueprint by name with the same link
 * (`Palette::buildFields()`). A user allowed there would see the forms assigned to
 * sites they cannot access.
 *
 * As with the editor's breadcrumb (#124), this listens for the response being
 * prepared, when the index is still an Inertia response and the palette still the
 * collection core's controller returned, and leaves out every form whose editor
 * the user may not open. A listener cannot replace the response, so the palette
 * is filtered in place; with a cache store that keeps objects rather than
 * serializing them, such as the array store, that is the cached palette too,
 * which then stays filtered for that user until core clears the cache. Core's
 * controllers are untouched, and every other response passes through.
 */
final class BlueprintListings
{
    /** The name of the blueprints index route. */
    public const string INDEX = 'statamic.cp.blueprints.index';

    /** The index page prop that lists the forms' blueprints. */
    public const string FORMS = 'forms';

    /** The name of the command palette's route. */
    public const string PALETTE = 'statamic.cp.command-palette.index';

    /**
     * Listen for responses being prepared.
     */
    public static function register(): void
    {
        Event::listen(PreparingResponse::class, self::preparing(...));
    }

    /**
     * Filter the blueprints index's forms, or the command palette's items.
     */
    public static function preparing(PreparingResponse $event): void
    {
        $request = $event->request;
        $route = $request instanceof Request ? $request->route() : null;
        $name = $route instanceof Route ? $route->getName() : null;
        $response = $event->response;

        if ($name === self::INDEX && $response instanceof InertiaResponse) {
            /** @var array<array-key, mixed> $forms core's `forms()` returns a list, untyped */
            $forms = app(BlueprintController::class)->forms();
            $response->with(self::FORMS, self::kept($forms, 'edit_url'));
        } elseif ($name === self::PALETTE && $response instanceof Collection) {
            /** @var Collection<array-key, mixed> $response */
            $kept = self::kept($response->all(), 'url');
            $response->splice(0);
            $response->push(...$kept);
        }
    }

    /**
     * The items, without those linking to a fields editor the user may not open.
     *
     * @param  array<array-key, mixed>  $items
     * @return list<mixed>
     */
    public static function kept(array $items, string $key): array
    {
        $refused = RefusedFormEditors::paths();

        return array_values(array_filter($items, static fn (mixed $item): bool => ! RefusedFormEditors::isRefused($item, $refused, $key)));
    }
}
