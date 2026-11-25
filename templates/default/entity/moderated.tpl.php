<?php

    /*
     * The notice on a post the moderators have taken down. Only its author and the
     * administrators can open such a post at all (see Entity::isModerated()).
     */

    $object = $vars['object'] ?? null;

if ($object instanceof \Idno\Common\Entity && $object->isModerated()) {
    $language = \Idno\Core\Idno::site()->language();
    if ($object->getModerationStatus() === \Idno\Common\Entity::MODERATION_HIDDEN) {
        $text = $language->_('Hidden because this account is banned. Only moderators can see this.');
    } else if ($object->getOwnerID() == \Idno\Core\Idno::site()->session()->currentUserUUID()) {
        $text = $language->_('Removed by the moderators. Only you can see this.');
    } else {
        $text = $language->_('Removed by the moderators. Only its author and moderators can see this.');
    }
    ?>
    <div class="idno-moderated-banner" role="note"><?php echo htmlspecialchars($text) ?></div>
    <?php
}
