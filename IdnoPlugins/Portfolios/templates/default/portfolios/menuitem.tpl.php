<?php

    /*
     * "Add to portfolio" in the "More" menu of a post (extends content/menu/items): only on
     * the signed-in member's own posts. It leads to the picker, which the plugin's script
     * opens in a dialog.
     */

    $object = $vars['object'] ?? null;
    $viewer = \Idno\Core\Idno::site()->session()->currentUser();

if (!empty($viewer) && $object instanceof \Idno\Common\Entity && $object->getID() && $object->getOwnerID() === $viewer->getUUID()) {
    $probe = new \IdnoPlugins\Portfolios\Portfolio();
    $probe->setOwner($viewer);
    if ($probe->accepts($object)) {
        ?>
            <a class="idno-more-item" role="menuitem" data-portfolio-pick href="<?php echo htmlspecialchars(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'portfolio/pick?object=' . rawurlencode((string) $object->getID()), ENT_QUOTES) ?>"><?php echo \Idno\Core\Idno::site()->language()->_('Add to portfolio') ?></a>
        <?php
    }
}
