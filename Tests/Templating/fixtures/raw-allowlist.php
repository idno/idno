<?php

declare(strict_types=1);

/**
 * Every `|raw` of a Twig template of core or the bundled plugins: the file (project-relative), and for each one the expression
 * that comes before `|raw` (white space between its parts as one space) with the reason it prints as markup. Only HTML a page or
 * another template already drew, and JSON encoded for a script with JSON_HEX_TAG, JSON_HEX_AMP, JSON_HEX_APOS and JSON_HEX_QUOT,
 * belong here. (`|raw` does not make a string markup for `action_link()` or `form_data()`: a label is a `{% set %}` capture.) Tests\Unit\Templating\RawUsageTest fails for a `|raw`
 * that is not listed and for an entry that matches none.
 *
 * @return array<string, list<array{expression: string, reason: string}>>
 */
return [];
