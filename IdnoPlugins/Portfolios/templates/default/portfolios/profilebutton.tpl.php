<?php

    /*
     * The "Portfolios" button of a profile (extends entity/User/profile/actions): for the
     * member themselves always, for anyone else when the member has a published portfolio.
     * Drawn on the profile and on the portfolio pages only, so that lists of members cost
     * no query each.
     */

    $member = $vars['user'] ?? null;
    $page = \Idno\Core\Idno::site()->currentPage();

if ($member instanceof \Idno\Entities\User && ($page instanceof \Idno\Pages\User\View || $page instanceof \IdnoPlugins\Portfolios\Pages\Base)) {
    $own = $member->getUUID() === \Idno\Core\Idno::site()->session()->currentUserUUID();
    if ($own || \IdnoPlugins\Portfolios\Portfolio::hasPublished($member)) {
        ?>
            <a href="<?php echo htmlspecialchars(\IdnoPlugins\Portfolios\Portfolio::listURL($member), ENT_QUOTES) ?>" data-portfolios-button class="idno-portfolios-button idno-btn idno-btn-ghost idno-btn-sm btn btn-default">
                <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/><rect width="20" height="14" x="2" y="6" rx="2"/></svg>
                <?php echo \Idno\Core\Idno::site()->language()->_('Portfolios') ?>
            </a>
        <?php
    }
}
