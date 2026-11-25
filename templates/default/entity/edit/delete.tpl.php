<?php

    /*
     * The Delete control at the foot of the edit page of an existing post (a draft above all,
     * which has no other page to delete it from). It is drawn by entity/edit/footer, which
     * every edit page draws after its form, so the delete form that the link submits never
     * sits inside the form that saves the post. Nothing is drawn for a post that has not been
     * saved yet, or that the viewer may not edit.
     */

    /* @var \Idno\Common\Entity $vars['object'] */
    $object = $vars['object'] ?? null;

if (!empty($object) && !empty($object->_id) && $object->canEdit()) {

    $language = \Idno\Core\Idno::site()->language();
    $is_draft = $object->getPublishStatus() === 'draft';
    $name = trim(preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) $object->getTitle()), ENT_QUOTES, 'UTF-8')));
    $name = mb_strimwidth($name, 0, 60, '…', 'UTF-8');

    if ($name === '') {
        $confirm = $is_draft ? $language->_('Delete this draft? This can\'t be undone.') : $language->_('Delete this post? This can\'t be undone.');
    } else {
        $confirm = $is_draft ? $language->_('Delete the draft "%s"? This can\'t be undone.', [$name]) : $language->_('Delete "%s"? This can\'t be undone.', [$name]);
    }

    ?>
        <div class="row">
            <div class="col-md-8 col-md-offset-2">
                <p class="edit-delete-bar" style="margin-top: 2em">
                    <?php echo \Idno\Core\Idno::site()->actions()->createLink(
                        $object->getDeleteURL(),
                        '<i class="fa fa-trash-o"></i> ' . htmlspecialchars($is_draft ? $language->_('Delete draft') : $language->_('Delete'), ENT_QUOTES),
                        [],
                        [
                            'method'       => 'POST',
                            'class'        => 'idno-edit-delete edit-delete text-danger',
                            'confirm'      => true,
                            'confirm-text' => $confirm,
                        ]
                    ); ?>
                </p>
            </div>
        </div>
    <?php

}
