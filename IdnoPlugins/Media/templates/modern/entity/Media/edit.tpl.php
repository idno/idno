<?php

    $object = $vars['object'];
    /* @var \IdnoPlugins\Media\Media $object */
    $lang = \Idno\Core\Idno::site()->language();

    $choose = empty($object->_id) ? $lang->_('Upload audio') : $lang->_('Choose different audio');

?>
<?php echo $this->draw('entity/edit/header'); ?>
<form action="<?= htmlspecialchars($object->getURL()) ?>" method="post" enctype="multipart/form-data">

    <div class="idno-editor">
        <h4 class="idno-editor-heading">
            <?php
            if (empty($object->_id)) {
                echo $lang->_('New audio');
            } else {
                echo $lang->_('Edit audio');
                if ($object->getPublishStatus() === 'draft') {
                    echo ' <span class="idno-badge-draft">' . $lang->_('Draft') . '</span>';
                }
            }
            ?>
        </h4>

        <div class="idno-form-field">
            <label class="idno-file-input" for="media">
                <?= $this->__(['icon' => 'music', 'class' => 'idno-file-input-icon'])->draw('shell/icon') ?>
                <span class="idno-file-input-text" id="media-filename" data-choose="<?= htmlspecialchars($choose) ?>"><?= htmlspecialchars($choose) ?></span>
                <?= $this->__([
                    'name' => 'media',
                    'id' => 'media',
                    'accept' => 'audio/*,video/*',
                    'required' => empty($object->_id),
                    'class' => 'idno-file-input-control'
                ])->draw('forms/input/file') ?>
            </label>
            <?= $this->__(['kinds' => \Idno\Core\Upload::typeList(['MP3', 'M4A', 'WAV', 'OGG', 'MP4', 'MOV', 'WebM']), 'several' => false, 'required' => ''])->draw('forms/upload/limits') ?>
        </div>

        <div class="idno-form-field">
            <label class="idno-label" for="title"><?= $lang->_('Title') ?></label>
            <?= $this->__([
                'name' => 'title',
                'id' => 'title',
                'placeholder' => $lang->_('Give it a title'),
                'value' => is_string($object->title) ? $object->title : '',
                'class' => 'idno-input'
            ])->draw('forms/input/input') ?>
        </div>

        <?= $this->__([
            'name'        => 'body',
            'value'       => $object->body,
            'wordcount'   => false,
            'class'       => 'wysiwyg-short',
            'height'      => 250,
            'placeholder' => $lang->_('Describe your audio'),
            'label'       => $lang->_('Description')
        ])->draw('forms/input/richtext') ?>

        <?= $this->draw('entity/tags/input') ?>
        <?= $this->drawSyndication('media', $object->getPosseLinks()) ?>
        <?php if (empty($object->_id)) {
            echo $this->__(['name' => 'forward-to', 'value' => \Idno\Core\Idno::site()->config()->getDisplayURL() . 'content/all/'])->draw('forms/input/hidden');
        } ?>
        <?= $this->draw('content/extra') ?>
        <?= $this->draw('content/access') ?>

        <?= \Idno\Core\Idno::site()->actions()->signForm('/media/edit') ?>

        <div class="idno-editor-actions">
            <button type="submit" class="idno-btn idno-btn-primary">
                <?= $lang->_('Publish') ?>
            </button>
            <a href="<?= !empty($object->_id) ? htmlspecialchars($object->getDisplayURL()) : \Idno\Core\Idno::site()->config()->getDisplayURL() ?>" class="idno-btn idno-btn-ghost">
                <?= $lang->_('Cancel') ?>
            </a>
        </div>

    </div>

</form>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        var input = document.getElementById('media');
        var name = document.getElementById('media-filename');
        if (input && name) {
            input.addEventListener('change', function () {
                // textContent, so a file name is never read as markup
                name.textContent = input.files && input.files.length ? input.files[0].name : name.getAttribute('data-choose');
            });
        }
    });
</script>
<?php echo $this->draw('entity/edit/footer');
