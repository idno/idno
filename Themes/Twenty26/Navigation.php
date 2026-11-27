<?php

namespace Themes\Twenty26 {

    use Idno\Common\Page;
    use Idno\Core\Idno;
    use Idno\Entities\User;

    /**
     * Which item of the side navigation (templates/modern/shell/nav.tpl.php) is the current page.
     *
     * The decision is made here, once, from the page that is being served (its class, and what it
     * knows about the request), never from the address: the site may live in a subdirectory and
     * an address carries a query string. At most one item is current. A page that belongs to no
     * item (a post's own page, its edit page, the compose page, someone else's profile) marks
     * none.
     *
     * Items are named by a key: home, profile, search, drafts, settings, admin, login.
     * A plugin's item has a key of its own, which its pages give in navigationItem().
     */
    class Navigation
    {

        /**
         * The key of the item that is the current page, or an empty string.
         *
         * @param Page|null $page   The page being served; the site's current page if not given
         * @param User|null $viewer The member looking at it; the signed-in member if not given
         */
        public static function currentItem(?Page $page = null, ?User $viewer = null): string
        {
            $page = $page ?? (Idno::site()->currentPage() ?: null);
            if (empty($page)) {
                return '';
            }
            // An error page (not found, gone, refused) is not the page of any item. The
            // not-found and refused pages are drawn by a Homepage, which would count as Home.
            $status = http_response_code();
            if ($status !== false && $status >= 400) {
                return '';
            }
            $viewer = $viewer ?? Idno::site()->session()->currentUser();
            $class = ltrim(get_class($page), '\\');

            // A page may say itself which item it belongs to (a plugin's pages, whose item the
            // plugin adds through shell/nav/items): navigationItem() returns the key, or an
            // empty string when the page belongs to no item for this viewer.
            if (method_exists($page, 'navigationItem')) {
                return (string) $page->navigationItem($viewer);
            }

            // The home feed and its content-type views (/content/...). A search, and a tag page,
            // which the site answers with a search, are drawn by the same page with a query.
            if ($page instanceof \Idno\Pages\Homepage) {
                return trim($page->getTextInput('q')) !== '' ? 'search' : 'home';
            }

            // A member's profile and its sub-pages: current only on the viewer's own profile.
            if ($page instanceof \Idno\Pages\User\View || $page instanceof \Idno\Pages\User\Edit) {
                $handle = (string) ($page->arguments[0] ?? '');
                if (!empty($viewer) && $handle !== '' && strcasecmp($handle, (string) $viewer->getHandle()) === 0) {
                    return 'profile';
                }

                return '';
            }

            if ($page instanceof \Themes\Twenty26\Pages\Drafts) {
                return 'drafts';
            }

            if ($page instanceof \Idno\Pages\Session\Login) {
                return 'login';
            }

            // Pages registered under /account/, which includes those that plugins add.
            if (Idno::site()->routes()->isAccountPage($page)) {
                return 'settings';
            }

            if (strpos($class, 'Idno\\Pages\\Admin\\') === 0) {
                return 'admin';
            }

            return '';
        }

        /**
         * The attributes of an item's link or button: the class, and for the current item the
         * "active" class (which the theme styles) and aria-current="page". The result is fixed
         * text, safe to print as it is.
         *
         * @param string      $item    The key of the item being drawn
         * @param string|null $current The key of the current item; decided here if not given
         */
        public static function attributes(string $item, ?string $current = null): string
        {
            $current = $current ?? static::currentItem();
            if ($current !== '' && $item === $current) {
                return 'class="idno-nav-item active" aria-current="page"';
            }

            return 'class="idno-nav-item"';
        }

    }

}
