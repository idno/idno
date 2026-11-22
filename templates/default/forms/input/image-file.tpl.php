<?php

if (empty($vars['id'])) {
    $vars['id'] = 'photo-' . md5(rand());
}

    $multiple = false;
if (strpos($vars['name'], '[]') !== false) {
    $multiple = true;
}

    $hide_existing = false;
if (!empty($vars['hide-existing'])) {
    $hide_existing = true;
}
?>
<div class="image-file-input">
    <div class="photo-preview-existing">
        <?php
        if (!empty($vars['object']->_id) && !$hide_existing) {

            $attachments = $vars['object']->getAttachments();
            foreach ($attachments as $attachment) {
                $filename = $attachment['filename'];

                $mainsrc = $attachment['url'];
                if (!empty($vars['object']->thumbs_large) && !empty($vars['object']->thumbs_large[$filename])) {
                    $src = $vars['object']->thumbs_large[$filename]['url'];

                    // Old style
                } else if (!empty($vars['object']->thumbnail_large)) {
                    $src = $vars['object']->thumbnail_large;

                    // Really old style
                } else if (!empty($vars['object']->thumbnail)) { // Backwards compatibility
                    $src = $vars['object']->thumbnail;

                    // Fallback
                } else {
                    $src = $mainsrc;
                }

                // Patch to correct certain broken URLs caused by https://github.com/idno/idno/issues/526
                $src = preg_replace('/^(https?:\/\/\/)/', \Idno\Core\Idno::site()->config()->getDisplayURL(), $src);
                $mainsrc = preg_replace('/^(https?:\/\/\/)/', \Idno\Core\Idno::site()->config()->getDisplayURL(), $mainsrc);

                $src = \Idno\Core\Idno::site()->config()->sanitizeAttachmentURL($src);
                $mainsrc = \Idno\Core\Idno::site()->config()->sanitizeAttachmentURL($mainsrc);
                ?>
                <div class="idno-image-existing">
                    <?php if ($vars['object']->canEdit() && empty($vars['hide-delete'])) { ?>
                    <span class="idno-image-delete delete-control">
                        <?php echo \Idno\Core\Idno::site()->actions()->createLink(
                            \Idno\Core\Idno::site()->config()->getDisplayURL() . 'attachment/' . $vars['object']->getId() . '/' . $attachment['_id'] . '/',
                            '<svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M3 6h18"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>',
                            [],
                            [
                                    'method' => 'POST',
                                    'class' => 'edit',
                                    'confirm' => true,
                                    'confirm-text' => \Idno\Core\Idno::site()->language()->_("Are you sure you want to permanently delete this?")
                            ]
                        ); ?>
                    </span>
                    <?php } ?>
                    <img src="<?php echo $this->makeDisplayURL($src) ?>" class="existing"/>
                </div>
                <?php
            }
        }
        ?>
    </div>
    <div class="photo-preview" id="<?php echo $vars['id']; ?>_preview">
        <img id="<?php echo $vars['id']; ?>_img" src="" class="preview" style="display:none; width: 400px;" />
    </div>
    <p>
        <label class="idno-btn idno-btn-ghost" for="<?php echo $vars['id']; ?>" style="width:100%;justify-content:center;cursor:pointer">
            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>
            <span class="photo-filename" data-nexttext="<?php echo \Idno\Core\Idno::site()->language()->_('Choose different photo'); ?>">
                <?php
                if (empty($vars['object']->_id)) {
                    echo \Idno\Core\Idno::site()->language()->_('Select a photo');
                } else {
                    if (!$multiple) {
                        echo \Idno\Core\Idno::site()->language()->_('Choose different photo');
                    } else {
                        echo \Idno\Core\Idno::site()->language()->_('Add photo');
                    }
                }
                ?>
            </span>
            <?php echo
            $this->__(
                [
                'name' => $vars['name'],
                'id' => $vars['id'],
                'accept' => 'image/*',
                'onchange' => 'Template.activateImagePreview(this)',
                'class' => 'input-file']
            )->draw('forms/input/file');
            ?>
        </label>
    </p>
</div>
