<?php

declare(strict_types=1);

/**
 * Every web and mail template (`.tpl.php` of the types default, email and email-text, in core and the bundled plugins) that has no Twig
 * port yet, by logical name `<base>:<type>/<name>`, sorted. A port removes its names; Tests\Unit\Templating\PortProgressTest holds this
 * list to the files.
 */
return [
    'IdnoPlugins/ActivityPub:default/activitypub/admin',
    'IdnoPlugins/ActivityPub:default/activitypub/admin/menu',
    'IdnoPlugins/Bridgy:default/bridgy/account',
    'IdnoPlugins/Bridgy:default/bridgy/facebook',
    'IdnoPlugins/Bridgy:default/bridgy/menu',
    'IdnoPlugins/Bridgy:default/bridgy/twitter',
    'IdnoPlugins/Checkin:default/checkin/head',
    'IdnoPlugins/IndiePub:default/account/indiepub',
    'IdnoPlugins/IndiePub:default/account/menu/items/indiepub',
    'IdnoPlugins/IndiePub:default/indiepub/auth',
    'IdnoPlugins/IndiePub:default/indiepub/shell/head',
    'IdnoPlugins/Media:default/media/shell/footer',
    'IdnoPlugins/Media:default/media/shell/head',
    'IdnoPlugins/StandardSiteSync:default/standardsitesync/account/menu',
    'IdnoPlugins/StandardSiteSync:default/standardsitesync/account/settings',
    'IdnoPlugins/StandardSiteSync:default/standardsitesync/admin/menu',
    'IdnoPlugins/StandardSiteSync:default/standardsitesync/admin/settings',
    'IdnoPlugins/StandardSiteSync:default/standardsitesync/head',
    'IdnoPlugins/StaticPages:default/staticpages/admin',
    'IdnoPlugins/StaticPages:default/staticpages/admin/categories',
    'IdnoPlugins/StaticPages:default/staticpages/admin/menu',
    'IdnoPlugins/StaticPages:default/staticpages/page',
    'IdnoPlugins/Styles:default/styles/admin',
    'IdnoPlugins/Styles:default/styles/admin/menu',
    'IdnoPlugins/Styles:default/styles/shell/head',
    'IdnoPlugins/Webhooks:default/webhooks/admin/home',
    'IdnoPlugins/Webhooks:default/webhooks/admin/menu',
];
