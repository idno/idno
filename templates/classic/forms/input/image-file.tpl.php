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
                <div class="existing-photo">
                    <?php if ($vars['object']->canEdit() && empty($vars['hide-delete'])) { ?>
                    <span class="delete-control">
                        <?php echo \Idno\Core\Idno::site()->actions()->createLink(
                            \Idno\Core\Idno::site()->config()->getDisplayURL() . 'attachment/' . $vars['object']->getId() . '/' . $attachment['_id'] . '/',
                            '<i class="fa fa-trash-o"></i>',
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
        <span class="btn btn-primary btn-file">
            <i class="fa fa-camera"></i> 
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
                'class' => 'input-file form-control col-md-9']
            )->draw('forms/input/file');
            ?>
        </span>
    </p>
</div>
