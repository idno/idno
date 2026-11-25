<?php

    /*
     * "Portfolios" in the Twenty26 side navigation (extends shell/nav/items): the signed-in
     * member's own list. The key of the current item is in $vars['nav_current'].
     */

    $member = \Idno\Core\Idno::site()->session()->currentUser();

if (!empty($member) && class_exists('\Themes\Twenty26\Navigation')) {
    ?>
        <li>
            <a href="<?= htmlspecialchars(\IdnoPlugins\Portfolios\Portfolio::listURL($member), ENT_QUOTES) ?>" <?= \Themes\Twenty26\Navigation::attributes('portfolios', (string) ($vars['nav_current'] ?? '')) ?>>
                <svg class="idno-nav-icon" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M16 20V4a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"/><rect width="20" height="14" x="2" y="6" rx="2"/></svg>
                <span class="idno-nav-label"><?= \Idno\Core\Idno::site()->language()->_('Portfolios') ?></span>
            </a>
        </li>
    <?php
}
