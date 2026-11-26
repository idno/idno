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
    'templates/default/admin/diagnostics.html.twig' => [
        ['expression' => 'details.message', 'reason' => 'a diagnostic message is HTML the page class (`Pages\\Admin\\Diagnostics`) and the listeners of `diagnostics/basics` write: a link to the update, a path already passed through `htmlspecialchars()`; the administrator\'s own page only places it'],
    ],
    'templates/email/shell.html.twig' => [
        ['expression' => 'body', 'reason' => 'the mail\'s body: HTML the mail template (or a third-party `.tpl.php` mail template) already drew and escaped; the shell only places it'],
    ],
    'templates/default/entity/FeedItem.html.twig' => [
        ['expression' => 'object.draw()', 'reason' => 'HTML an entity has already drawn through its own templates (`Entity::draw()`); the page only places it. Escaping what members and other sites supplied is the job of those templates (those of the bundled plugins are ported and checked on their own)'],
    ],
    'templates/default/entity/User/profile.html.twig' => [
        ['expression' => 'user.draw()', 'reason' => 'HTML an entity has already drawn through its own templates(`Entity::draw()`); the page only places it. Escaping what members and other sites supplied is the job of those templates(those of the bundled plugins are ported and checked on their own)'],
        ],
        'templates/default/entity/editwrapper.html.twig' => [
        ['expression' => 'body', 'reason' => 'the page\'s body: the edit form a content type\'s edit page already drew; the wrapper only places it'],
        ['expression' => 'object.drawEdit()', 'reason' => 'the edit form of the entity (`Entity::drawEdit()`), drawn by its own templates, which are responsible for escaping what a member supplied'],
        ],
        'templates/default/entity/shell.html.twig' => [
        ['expression' => 'body', 'reason' => 'the page\'s body: HTML the page (or the templates it drew) already built and escaped; the shell only places it'],
    ['expression' => 'object.draw()', 'reason' => 'HTML an entity has already drawn through its own templates (`Entity::draw()`); the page only places it. Escaping what members and other sites supplied is the job of those templates (those of the bundled plugins are ported and checked on their own)'],
    ],
    'templates/default/file/picker/donejs.html.twig' => [
        ['expression' => '(static_url() ~ \'file/\' ~ entity_value(file, \'file\')._id)|json_encode(constant(\'JSON_HEX_TAG\') b-or constant(\'JSON_HEX_AMP\') b-or constant(\'JSON_HEX_APOS\') b-or constant(\'JSON_HEX_QUOT\'))', 'reason' => 'JSON for a script, encoded with all four JSON_HEX_* flags, so that the id of the file cannot end the script or open a tag'],
    ],
    'templates/default/forms/action.html.twig' => [
        ['expression' => 'body', 'reason' => 'the body of the form: the controls its caller (`Actions::createForm()`) already drew; the form only places it'],
    ],
    'templates/default/forms/link.html.twig' => [
        ['expression' => 'label', 'reason' => 'the label of the link: markup its caller built (an icon and text). `action_link()` escapes a label that is not markup before it draws this template, and the legacy `Actions::createLink()` callers pass markup'],
    ],
    'templates/default/following/confirm.html.twig' => [
        ['expression' => 'item.draw()', 'reason' => 'a feed item\'s own drawing (`entity/FeedItem`), which escapes what the remote feed supplied'],
    ],
    'templates/default/following/home.html.twig' => [
        ['expression' => 'subscription.draw()', 'reason' => 'a subscription\'s own drawing (`entity/Subscription`), which escapes what the followed feed supplied'],
    ],
    'templates/default/js/idno.html.twig' => [
        ['expression' => 'environment|json_encode(constant(\'JSON_HEX_TAG\') b-or constant(\'JSON_HEX_AMP\') b-or constant(\'JSON_HEX_APOS\') b-or constant(\'JSON_HEX_QUOT\'))', 'reason' => 'JSON for a script, encoded with all four JSON_HEX_* flags, so no value in it (the page\'s address holds the visitor\'s words) can end the script or open a tag'],
    ],
    'templates/default/shell.html.twig' => [
        ['expression' => 'shell.html_className', 'reason' => 'an attribute string (` class="h-feed"` or nothing) that shell_variables() builds from fixed text, as the old shell printed it'],
        ['expression' => 'shell.body', 'reason' => 'the page\'s body: HTML the page (or the templates it drew) already built and escaped; the shell only places it'],
    ],
    'templates/default/shell/messages/message.html.twig' => [
        ['expression' => 'message.message', 'reason' => 'a session message is markup that the code which added it wrote (it may hold a link): fixed text is its own, and any member, request, remote or exception text in a message is escaped by the caller first (`esc_()`, `htmlspecialchars()`, the AT Protocol plugin\'s `Main::sessionMessage()`; every `addMessage()` and `addErrorMessage()` call of core and the bundled plugins was checked for this)'],
    ],
    'templates/default/shell/metatags.html.twig' => [
        ['expression' => 'title_className', 'reason' => 'an attribute string (` class="p-name"` or nothing) that shell_variables() builds from fixed text, as the old partial printed it'],
    ],
    'templates/default/shell/structured-data.html.twig' => [
        ['expression' => 'jsonld|json_encode(constant(\'JSON_PRETTY_PRINT\') b-or constant(\'JSON_UNESCAPED_SLASHES\') b-or constant(\'JSON_HEX_TAG\'))', 'reason' => 'JSON for a JSON-LD script (a data block that nothing runs): JSON_HEX_TAG turns every `<` and `>` of a value into an escape, so no value can end the script or open a comment; it keeps the document\'s own layout (pretty printed, slashes as written)'],
    ],
    'IdnoPlugins/StaticPages/templates/default/staticpages/page.html.twig' => [
        ['expression' => 'object.draw()', 'reason' => 'HTML a static page has already drawn through its own template (`Entity::draw()`, `entity/StaticPage`), which escapes the title and the forward address; the page only places it'],
    ],
    'IdnoPlugins/StaticPages/templates/default/entity/StaticPage.html.twig' => [
        ['expression' => 'entity_value(object, \'body\')', 'reason' => 'administrator-authored page HTML, as stored; only administrators can write it (the plugin\'s edit page is behind `adminGatekeeper()`), with an editor that lets them write raw HTML, and the site\'s purifier would remove what a page legitimately holds (an `id` anchor, a form, a style block, an embed from a host it does not list). The linkers then link its text and never the inside of a tag'],
    ],
];
