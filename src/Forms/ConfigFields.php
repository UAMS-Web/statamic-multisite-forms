<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Forms;

use Statamic\Facades\Form;
use UAMSWeb\MultisiteForms\Sites\ConfiguredSites;

/**
 * The Sites section the add-on adds to every form's settings screen.
 *
 * It uses core's own extension point, `Form::appendConfigFields()`, so no
 * Control Panel view is replaced: core builds the section into the edit
 * blueprint, validates and processes it, and merges the value into the form's
 * data, which `Form::save()` writes to the form file. An empty selection
 * processes to null, which core leaves out of the file, so clearing the field
 * makes the form global again.
 */
final class ConfigFields
{
    /** The section's display name; core keys the section by its snake case, `sites`. */
    public const string SECTION = 'Sites';

    /**
     * Add the Sites section to the settings screen of every form.
     */
    public static function register(): void
    {
        Form::appendConfigFields('*', self::SECTION, self::fields());
    }

    /**
     * The section's fields, keyed by handle, in the shape core's form settings use.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fields(): array
    {
        return [
            Assignment::KEY => [
                // No `display`: core labels the field with the title-cased handle, "Sites".
                'type' => 'sites',
                'instructions' => 'The sites this form belongs to. Leave it empty to use the form on every site.',
                'mode' => 'select',
                'validate' => ['new '.ConfiguredSites::class],
            ],
        ];
    }
}
