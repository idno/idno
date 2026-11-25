<?php

    /*
     * "Edit" and "Delete" are in the post's "More" menu (content/menu). What is left here
     * is what a content type adds for those who may edit its posts.
     */

    /* @var \Idno\Common\Entity $vars['object'] */

if ($vars['object']->canEdit()) {
    echo $this->draw('content/entity/' . $vars['object']->getEntityTypeName() . '/edit');
}
