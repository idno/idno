<?php

    /*
     * The "More" menu of a comment in Twenty26: "Copy link" (the comment's anchor on its
     * post), "Delete" for whoever may remove the comment, and then what plugins add through
     * entity/annotations/menu/items.
     */

    $object = $vars['object'] ?? null;
    $annotation = $vars['annotation'] ?? null;
    $local = (string) ($vars['annotation_permalink'] ?? '');

if ($object instanceof \Idno\Common\Entity && is_array($annotation) && $local !== '') {
    $language = \Idno\Core\Idno::site()->language();
    $items = '';
    if ($object->canEditAnnotation($annotation)) {
        $items .= \Idno\Core\Idno::site()->actions()->createLink(
            $object->getDisplayUrl() . '/annotation/delete?permalink=' . \Idno\Core\Webservice::base64UrlEncode($local),
            $language->_('Delete'),
            [],
            ['method' => 'POST', 'class' => 'idno-more-item idno-more-item-danger']
        );
    }
    $items .= $this->__(['object' => $object, 'annotation' => $annotation, 'annotation_permalink' => $local])->draw('entity/annotations/menu/items');
    echo $this->__(
        [
        'label' => $language->_('More options for this comment by %s', [strip_tags((string) ($annotation['owner_name'] ?? ''))]),
        'copy_url' => $object->getDisplayURL() . '#' . \Idno\Common\Entity::annotationAnchor($local),
        'menu_items' => $items,
        ]
    )->draw('shell/moremenu');
}
