<?php

    /*
     * The "More" menu of a post. Plugins add items by extending content/menu/items,
     * which is drawn with the post in $vars['object'].
     */

    /* @var \Idno\Common\Entity $object */
    $object = $vars['object'] ?? null;

if ($object instanceof \Idno\Common\Entity) {
    $owner = $object->getOwner();
    $name = $owner ? strip_tags((string) $owner->getTitle()) : '';
    $items = $this->draw('content/menu/items');
    echo $this->__(
        [
        'label' => \Idno\Core\Idno::site()->language()->_('More options for this post by %s', [$name]),
        'copy_url' => $object->getDisplayURL(),
        'items' => $items,
        ]
    )->draw('shell/moremenu');
}
