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
        <img id="<?php echo $vars['id']; ?>_img" alt="" class="preview" style="display:none; width:100%; max-width:400px; border-radius:8px; margin-bottom:0.75rem;" />
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
                'accept' => \Idno\Core\Upload::imageAccept(),
                'class' => 'input-file']
            )->draw('forms/input/file');
            ?>
        </label>
    </p>
</div>
<?php
// The preview and the button's text, in plain JavaScript (Twenty26 loads no jQuery). Drawn once
// a page; it reads everything from the elements, and holds no value from the post.
global $idno_image_file_script_drawn;
if (empty($idno_image_file_script_drawn)) {
    $idno_image_file_script_drawn = true;
    ?>
<script>
    (function () {
        if (window.idnoImageFileInput) {
            return;
        }
        window.idnoImageFileInput = true;
        document.addEventListener('change', function (event) {
            var input = event.target;
            if (!input || input.type !== 'file') {
                return;
            }
            var holder = input.closest('.image-file-input');
            if (!holder) {
                return;
            }
            var preview = holder.querySelector('img.preview');
            var label = holder.querySelector('.photo-filename');
            var file = input.files && input.files.length ? input.files[0] : null;
            if (label) {
                if (!label.hasAttribute('data-choose')) {
                    label.setAttribute('data-choose', label.textContent.trim());
                }
                // textContent, so a file name is never read as markup
                label.textContent = file ? file.name : label.getAttribute('data-choose');
            }
            if (preview) {
                if (preview.src && preview.src.indexOf('blob:') === 0) {
                    URL.revokeObjectURL(preview.src);
                }
                if (file && window.URL && URL.createObjectURL) {
                    preview.src = URL.createObjectURL(file);
                    preview.style.display = 'block';
                } else {
                    preview.removeAttribute('src');
                    preview.style.display = 'none';
                }
            }
        });
    })();
</script>
    <?php
}
