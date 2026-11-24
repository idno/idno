<?php

    /*
     * The "More" menu of a profile. Plugins add items by extending entity/User/menu/items,
     * which is drawn with the member in $vars['user'].
     */

    $user = $vars['user'] ?? null;

if ($user instanceof \Idno\Entities\User) {
    $items = $this->draw('entity/User/menu/items');
    echo $this->__(
        [
        'label' => \Idno\Core\Idno::site()->language()->_('More options for %s', [strip_tags((string) $user->getTitle())]),
        'copy_url' => $user->getDisplayURL(),
        'items' => $items,
        ]
    )->draw('shell/moremenu');
}
