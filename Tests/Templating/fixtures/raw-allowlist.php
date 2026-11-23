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
return [
    'templates/default/account-shell.html.twig' => [
        ['expression' => 'shell.body', 'reason' => 'the page\'s body: HTML the page (or the templates it drew) already built and escaped; the shell only places it'],
    ],
    'templates/default/admin-shell.html.twig' => [
        ['expression' => 'shell.body', 'reason' => 'the page\'s body: HTML the page (or the templates it drew) already built and escaped; the shell only places it'],
    ],
    'templates/default/js/idno.html.twig' => [
        ['expression' => 'environment|json_encode(constant(\'JSON_HEX_TAG\') b-or constant(\'JSON_HEX_AMP\') b-or constant(\'JSON_HEX_APOS\') b-or constant(\'JSON_HEX_QUOT\'))', 'reason' => 'JSON for a script, encoded with all four JSON_HEX_* flags, so no value in it (the page\'s address holds the visitor\'s words) can end the script or open a tag'],
    ],
    'templates/default/shell.html.twig' => [
        ['expression' => 'shell.html_className', 'reason' => 'an attribute string (` class="h-feed"` or nothing) that shell_variables() builds from fixed text, as the old shell printed it'],
        ['expression' => 'shell.body', 'reason' => 'the page\'s body: HTML the page (or the templates it drew) already built and escaped; the shell only places it'],
    ],
    'templates/default/shell/metatags.html.twig' => [
        ['expression' => 'title_className', 'reason' => 'an attribute string (` class="p-name"` or nothing) that shell_variables() builds from fixed text, as the old partial printed it'],
    ],
    'templates/default/shell/messages/message.html.twig' => [
        ['expression' => 'message.message', 'reason' => 'a session message is markup set by the code that added it (it may hold a link), which escapes member and request text with `esc_()`; the one known caller that passes an exception\'s visitor message is Idno\\Pages\\Admin\\EmailTest, through ExceptionMessage::forVisitor()'],
    ],
    'templates/default/shell/simple.html.twig' => [
        ['expression' => 'body', 'reason' => 'the page\'s body: HTML the page (or the templates it drew) already built and escaped; the shell only places it'],
    ],
    'templates/default/shell/simple/messages.html.twig' => [
        ['expression' => 'message.message', 'reason' => 'a session message is markup set by the code that added it (it may hold a link), which escapes member and request text with `esc_()`; the one known caller that passes an exception\'s visitor message is Idno\\Pages\\Admin\\EmailTest, through ExceptionMessage::forVisitor(); autop() then wraps it in paragraphs and would escape it as text without this'],
    ],
    'templates/default/shell/structured-data.html.twig' => [
        ['expression' => 'jsonld|json_encode(constant(\'JSON_PRETTY_PRINT\') b-or constant(\'JSON_UNESCAPED_SLASHES\') b-or constant(\'JSON_HEX_TAG\'))', 'reason' => 'JSON for a JSON-LD script (a data block that nothing runs): JSON_HEX_TAG turns every `<` and `>` of a value into an escape, so no value can end the script or open a comment; it keeps the document\'s own layout (pretty printed, slashes as written)'],
    ],
];
