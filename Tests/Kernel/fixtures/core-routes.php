<?php

declare(strict_types=1);

/**
 * The legacy route table's core registrations as they were at commit 69af0d425, before any page moved to a controller: every key that core and the
 * components it builds registered, in the order the table held them, with the marker `@plugins` at the point where the plugins' routes are registered
 * (the plugins are built at the end of `Idno::registerPages()`: the keys after the marker are those of the components `LegacyBootstrap::boot()` builds
 * after `Idno`: Account, Admin, Webfinger, Webmention, WebSub). Keys are as the table holds them (the tokens replaced), the four of the permalink
 * route written `{permalink}`.
 *
 * Generated from that commit's code, not by hand: check the commit out, install its `vendor/`, configure a site with every plugin in `antiplugins`
 * and list `array_keys(iterator_to_array(Idno::site()->routes()))` after `Idno/start.php` has booted it; the keys that follow the first fixture page registered
 * on `plugins/loaded` (the three `draw-legacy` pages of the test environment, replaced here by the marker) are the late ones.
 *
 * `SitePageClaimsTest` holds the booted application's table to it: each key must still be there, in this order, on the same side of the marker
 * (a kept legacy page, a shell and a placeholder all count as the key being there). A route removed on purpose is deleted from `routes` and listed
 * in `omitted` with its reason, in the same change. A key core registered AFTER that commit is not listed (the snapshot is the table as it was; the check
 * ignores keys it does not hold): the two keys that send a post's address in another layout to its own (`Idno\Legacy\Placeholder\PermalinkOtherLayout`) are held
 * by `SitePageClaimsTest::ROUTED_KEYS` instead. `{permalink}` is the one fixed layout, `/[0-9]{4}/([^/.]+)`.
 */

return [
    'routes' => [
        '/session/login',
        '/session/logout',
        '/currentUser/?',
        '/?',
        '/feed\\.xml',
        '/feed/?',
        '/rss\\.xml',
        '/content/([A-Za-z\\-\\/]+)+',
        '/view/([A-Za-z0-9\\-]+)/?',
        '/s/([A-Za-z0-9\\-]+)/?',
        '{permalink}/?',
        '/edit/([A-Za-z0-9\\-]+)/?',
        '/delete/([A-Za-z0-9\\-]+)/?',
        '/withdraw/([A-Za-z0-9\\-]+)/?',
        '/attachment/([A-Za-z0-9\\-]+)/([A-Za-z0-9\\-]+)/?',
        '/view/([A-Za-z0-9\\-]+)/annotations/([A-Za-z0-9\\-]+)?',
        '{permalink}/annotations/([A-Za-z0-9\\-]+)?',
        '{permalink}/annotations/([A-Za-z0-9\\-]+)/delete/?',
        '{permalink}/annotation/delete/?',
        '/annotation/post/?',
        '/share/?',
        '/chrome/manifest\\.json',
        '/service-worker(\\.min)?\\.js',
        '/file/mint/?',
        '/file/upload/?',
        '/file/picker/?',
        '/filepicker/?',
        '/file/(([A-Za-z0-9\\-]+))(/.*)?',
        '/profile/([^\\/]+)/?',
        '/profile/([^\\/]+)/edit/?',
        '/profile/([^\\/]+)/([A-Za-z\\-\\/]+)+',
        '/actor/([^\\/]+)/?',
        '/search/?',
        '/search/mentions\\.json',
        '/tag/([^\\s]+)\\/?',
        '/search/users/?',
        '/robots\\.txt',
        '/autosave/?',
        '/drafts/?',
        '/service/db/optimise/?',
        '/service/security/csrftoken/?',
        '/service/system/log/?',
        '/service/geo/geocoder/?',
        '@plugins',
        '/account/settings/?',
        '/account/settings/tools/?',
        '/account/settings/following/?',
        '/account/settings/following/bookmarklet/?',
        '/account/register/?',
        '/account/password/?',
        '/account/password/reset/?',
        '/account/settings/feedback/?',
        '/account/settings/feedback/confirm/?',
        '/account/export/?',
        '/account/export/rss/?',
        '/account/export/wxr/?',
        '/admin/?',
        '/admin/plugins/?',
        '/admin/themes/?',
        '/admin/dependencies/?',
        '/admin/homepage/?',
        '/admin/email/?',
        '/admin/emailtest/?',
        '/admin/about/?',
        '/admin/users/?',
        '/admin/export/?',
        '/admin/export/generate/?',
        '/admin/export/rss/?',
        '/admin/export/wxr/?',
        '/admin/import/?',
        '/admin/diagnostics/?',
        '/admin/statistics/?',
        '/admin/legacytables/?',
        '/\\.well\\-known/webfinger/?',
        '/webmention/?',
        '/pubsub/callback/([A-Za-z0-9\\-]+)/([A-Za-z0-9\\-]+)/?',
    ],
    'omitted' => [
        '/following/?' => 'removed with the feed reader: a feed could not be followed on a supported PHP (the feed parser called DOMDocument::loadHTML() statically, an error on PHP 8, so the pages answered the 500 page), the records it saved were saved to one collection and read from another, and nothing linked the address; nothing registers it, so a plugin\'s broader pattern, or the 404 page, answers it',
        '/following/add/?' => 'removed with the feed reader: a feed could not be followed on a supported PHP (the feed parser called DOMDocument::loadHTML() statically, an error on PHP 8, so the pages answered the 500 page), the records it saved were saved to one collection and read from another, and nothing linked the address; nothing registers it, so a plugin\'s broader pattern, or the 404 page, answers it',
        '/following/confirm/?' => 'removed with the feed reader: a feed could not be followed on a supported PHP (the feed parser called DOMDocument::loadHTML() statically, an error on PHP 8, so the pages answered the 500 page), the records it saved were saved to one collection and read from another, and nothing linked the address; nothing registers it, so a plugin\'s broader pattern, or the 404 page, answers it',
        '/following/refresh/?' => 'removed with the feed reader: a feed could not be followed on a supported PHP (the feed parser called DOMDocument::loadHTML() statically, an error on PHP 8, so the pages answered the 500 page), the records it saved were saved to one collection and read from another, and nothing linked the address; nothing registers it, so a plugin\'s broader pattern, or the 404 page, answers it',
        '/stream/?' => 'removed outright: the page listed the items of the feeds a member followed, none of which can be made on a supported PHP (the feed parser called DOMDocument::loadHTML() statically, an error on PHP 8), nothing linked the address, and with no item it drew nothing; it has no registration, so a plugin\'s broader pattern, or the 404 page, answers it',
        '/bookmarklet\\.js' => 'removed outright when its page moved: the address answered an HTML fragment under a .js name and nothing linked it; it has no registration, so a plugin\'s broader pattern, or the 404 page, answers it',
        '/content/default/?' => 'registered by the StaticPages plugin, not core: removed when a static page became the home page through the homepage/replace event',
        '/begin/?' => 'removed with the onboarding pages when the installer was rebuilt inside the kernel (Idno\\Install): the first administrator is made by the installation\'s last step (`/_setup/`) or `idno:admin:create`, so nothing registers the address and a plugin\'s broader pattern, or the 404 page, answers it',
        '/begin/register/?' => 'removed with the onboarding pages when the installer was rebuilt inside the kernel (Idno\\Install): the first administrator is made by the installation\'s last step (`/_setup/`) or `idno:admin:create`, so nothing registers the address and a plugin\'s broader pattern, or the 404 page, answers it',
        '/begin/profile/?' => 'removed with the onboarding pages when the installer was rebuilt inside the kernel (Idno\\Install): the first administrator is made by the installation\'s last step (`/_setup/`) or `idno:admin:create`, so nothing registers the address and a plugin\'s broader pattern, or the 404 page, answers it',
        '/begin/connect/?' => 'removed with the onboarding pages when the installer was rebuilt inside the kernel (Idno\\Install): the first administrator is made by the installation\'s last step (`/_setup/`) or `idno:admin:create`, so nothing registers the address and a plugin\'s broader pattern, or the 404 page, answers it',
        '/begin/connect\\-forwarder/?' => 'removed with the onboarding pages when the installer was rebuilt inside the kernel (Idno\\Install): the first administrator is made by the installation\'s last step (`/_setup/`) or `idno:admin:create`, so nothing registers the address and a plugin\'s broader pattern, or the 404 page, answers it',
        '/begin/publish/?' => 'removed with the onboarding pages when the installer was rebuilt inside the kernel (Idno\\Install): the first administrator is made by the installation\'s last step (`/_setup/`) or `idno:admin:create`, so nothing registers the address and a plugin\'s broader pattern, or the 404 page, answers it',
    ],
];
