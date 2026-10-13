<?php

declare(strict_types=1);

namespace UAMSWeb\MultisiteForms\Forms;

use InvalidArgumentException;

/**
 * What the form tags do with a form not assigned to the page's site, read from
 * `statamic-multisite-forms.not_assigned`.
 */
enum NotAssigned: string
{
    /** Render nothing and log a warning. */
    case Empty = 'empty';

    /** Abort the request with a 404. */
    case NotFound = '404';

    public const string CONFIG_KEY = 'statamic-multisite-forms.not_assigned';

    /**
     * The configured setting. An integer 404 is accepted, since a config file
     * can easily say `404` without quotes.
     *
     * @throws InvalidArgumentException When the configured value is not a setting.
     */
    public static function fromConfig(): self
    {
        $value = config(self::CONFIG_KEY, self::Empty->value);
        $setting = is_string($value) || is_int($value) ? self::tryFrom((string) $value) : null;

        if ($setting === null) {
            throw new InvalidArgumentException(sprintf(
                'The %s setting must be "empty" or "404"; got %s.',
                self::CONFIG_KEY,
                var_export($value, true),
            ));
        }

        return $setting;
    }
}
