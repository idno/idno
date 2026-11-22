<?php

    $attachments = $vars['object']->getAttachments();
    $multiple = false;
    $num_pics = count($attachments);
    if ($num_pics > 1)
        $multiple = true;
    $cnt = 0;
?>

<?php echo $this->draw('entity/edit/header'); ?>
    <form action="<?= $vars['object']->getURL() ?>" method="post" enctype="multipart/form-data">

        <div class="idno-editor">

                <h4 class="idno-editor-heading">
                    <?php
                    if (empty($vars['object']->_id)) {
                        echo \Idno\Core\Idno::site()->language()->_('New Photo');
                    } else {
                        echo \Idno\Core\Idno::site()->language()->_('Edit Photo');
                        if ($vars['object']->getPublishStatus() === 'draft') {
                            echo ' <span class="idno-badge-draft">' . \Idno\Core\Idno::site()->language()->_('Draft') . '</span>';
                        }
                    }
                    ?>
                </h4>

                <div class="idno-photo-files <?php if ($multiple) echo "multiple-images"; ?>" data-num-pics="<?= $num_pics ?>">
                    <?php for ($n = 0; $n < 10; $n++) { ?>
                        <div class="idno-image-file" data-number="<?= $n ?>" style="<?php if ($n > 0) echo 'display: none;'; ?>">
                            <?= $this->__([
                                'name' => 'photo[]',
                                'hide-existing' => $n > 0,
                                'hide-delete' => $n > 0
                            ])->draw('forms/input/image-file') ?>
                        </div>
                    <?php } ?>
                </div>

                <div id="photo-details">

                    <div class="idno-form-field">
                        <label class="idno-label" for="title">
                            <?= \Idno\Core\Idno::site()->language()->_('Title') ?></label>
                        <?= $this->__([
                            'name' => 'title',
                            'id' => 'title',
                            'placeholder' => \Idno\Core\Idno::site()->language()->_('Give it a title'),
                            'value' => $vars['object']->title,
                            'class' => 'idno-input'
                        ])->draw('forms/input/input') ?>
                    </div>

                    <?= $this->__([
                        'name' => 'body',
                        'value' => $vars['object']->body,
                        'wordcount' => false,
                        'class' => 'wysiwyg-short',
                        'height' => 100,
                        'placeholder' => \Idno\Core\Idno::site()->language()->_('Describe your photo'),
                        'label' => \Idno\Core\Idno::site()->language()->_('Description')
                    ])->draw('forms/input/richtext') ?>

                    <?= $this->draw('entity/tags/input') ?>

                </div>

                <?= $this->drawSyndication('image', $vars['object']->getPosseLinks()) ?>
                <?php if (empty($vars['object']->_id)) {
                    echo $this->__(['name' => 'forward-to', 'value' => \Idno\Core\Idno::site()->config()->getDisplayURL() . 'content/all/'])->draw('forms/input/hidden');
                } ?>
                <?= $this->draw('content/extra') ?>
                <?= $this->draw('content/access') ?>

                <?= \Idno\Core\Idno::site()->actions()->signForm('/photo/edit') ?>

                <div style="display:flex;gap:0.5rem;margin-top:var(--spacing-section)">
                    <button type="submit" class="idno-btn idno-btn-primary" name="publish_status" value="published">
                        <?= \Idno\Core\Idno::site()->language()->_('Publish') ?>
                    </button>
                    <button type="submit" class="idno-btn idno-btn-ghost" name="publish_status" value="draft">
                        <?= \Idno\Core\Idno::site()->language()->_('Save as Draft') ?>
                    </button>
                    <a href="<?= !empty($vars['object']->_id) ? $vars['object']->getDisplayURL() : \Idno\Core\Idno::site()->config()->getDisplayURL() ?>" class="idno-btn idno-btn-ghost">
                        <?= \Idno\Core\Idno::site()->language()->_('Cancel') ?>
                    </a>
                </div>

        </div>
    </form>
<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.idno-photo-files input').forEach(function(input) {
            input.addEventListener('change', function() {
                var imageFile = this.closest('div.idno-image-file');
                var number = parseInt(imageFile.getAttribute('data-number')) + 1;
                var next = document.querySelector('.idno-photo-files .idno-image-file[data-number="' + number + '"]');
                if (next) next.style.display = '';
            });
        });
    });
</script>

<?php echo $this->draw('entity/edit/footer');
