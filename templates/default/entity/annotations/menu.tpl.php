<?php

    /*
     * The "More" menu of a comment. A comment's link is an anchor on its post:
     * <post>#annotation-<id>. Plugins add items by extending entity/annotations/menu/items,
     * which is drawn with the post in $vars['object'], the comment in $vars['annotation'] and
     * the comment's local address in $vars['annotation_permalink'].
     */

    $object = $vars['object'] ?? null;
    $local = (string) ($vars['annotation_permalink'] ?? '');

if ($object instanceof \Idno\Common\Entity && $local !== '') {
    $items = $this->draw('entity/annotations/menu/items');
    echo $this->__(
        [
        'label' => \Idno\Core\Idno::site()->language()->_('More options for this comment by %s', [strip_tags((string) ($vars['annotation']['owner_name'] ?? ''))]),
        'copy_url' => $object->getDisplayURL() . '#' . \Idno\Common\Entity::annotationAnchor($local),
        'menu_items' => $items,
        ]
    )->draw('shell/moremenu');
}
