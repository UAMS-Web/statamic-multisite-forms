<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Forms not assigned to the current site
    |--------------------------------------------------------------------------
    |
    | What the {{ form }} tags do with a form that is not assigned to the site
    | the page is rendered for.
    |
    | "empty": render nothing, and log a warning naming the form and the site
    |          once per form per request. Suits a shared layout or partial that
    |          renders a form on every site when only some sites use it.
    | "404":   abort the request with a 404.
    |
    | A submit to an unassigned form always answers 404, whatever this says.
    |
    */

    'not_assigned' => 'empty',

];
