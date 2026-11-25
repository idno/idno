<?php

    /*
     * The "More" menu of a post in Twenty26: "Copy link" for everyone, "Edit" and "Delete"
     * for whoever may edit the post, and then what plugins add through content/menu/items
     * (drawn with the post in $vars['object']).
     */

    /* @var \Idno\Common\Entity $object */
    $object = $vars['object'] ?? null;

if ($object instanceof \Idno\Common\Entity) {
    $language = \Idno\Core\Idno::site()->language();
    $owner = $object->getOwner();
    $name = $owner ? strip_tags((string) $owner->getTitle()) : '';
    $items = '';
    if ($object->canEdit()) {
        $items .= '<a class="idno-more-item" role="menuitem" href="' . htmlspecialchars($object->getEditURL(), ENT_QUOTES) . '">' . $language->_('Edit') . '</a>';
        $items .= \Idno\Core\Idno::site()->actions()->createLink(
            $object->getDeleteURL(),
            $language->_('Delete'),
            [],
            [
                'method' => 'POST',
                'class' => 'idno-more-item idno-more-item-danger',
                'confirm' => true,
                'confirm-text' => $language->_('Are you sure you want to permanently delete this entry?')
            ]
        );
    }
    $items .= $this->__(['object' => $object])->draw('content/menu/items');
    echo $this->__(
        [
        'label' => $language->_('More options for this post by %s', [$name]),
        'copy_url' => $object->getDisplayURL(),
        'menu_items' => $items,
        ]
    )->draw('shell/moremenu');
}
