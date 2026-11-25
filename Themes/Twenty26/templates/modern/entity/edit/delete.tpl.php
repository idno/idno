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
        <style>
            .idno-editor-delete .idno-edit-delete { color: #b91c1c; border-color: #fecaca; }
            .idno-editor-delete .idno-edit-delete:hover { background: #fef2f2; }
        </style>
        <div class="idno-editor-delete" style="display:flex;justify-content:flex-end;margin-top:var(--spacing-section)">
            <?= \Idno\Core\Idno::site()->actions()->createLink(
                $object->getDeleteURL(),
                '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg> '
                    . htmlspecialchars($is_draft ? $language->_('Delete draft') : $language->_('Delete'), ENT_QUOTES),
                [],
                [
                    'method'       => 'POST',
                    'class'        => 'idno-edit-delete idno-btn idno-btn-ghost idno-btn-sm',
                    'title'        => $is_draft ? $language->_('Delete draft') : $language->_('Delete'),
                    'confirm'      => true,
                    'confirm-text' => $confirm,
                ]
            ) ?>
        </div>
    <?php

}
