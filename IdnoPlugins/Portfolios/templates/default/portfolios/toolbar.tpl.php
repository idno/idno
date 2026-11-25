<?php

    /*
     * "Portfolios" in the member's menu of the default look (extends shell/toolbar/personal/items).
     */

    $member = \Idno\Core\Idno::site()->session()->currentUser();

if (!empty($member)) {
    ?>
        <li><a href="<?php echo htmlspecialchars(\IdnoPlugins\Portfolios\Portfolio::listURL($member), ENT_QUOTES) ?>"><?php echo \Idno\Core\Idno::site()->language()->_('Portfolios') ?></a></li>
    <?php
}
