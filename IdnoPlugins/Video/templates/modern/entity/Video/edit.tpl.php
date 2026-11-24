<?php

    $object = $vars['object'];
    /* @var \IdnoPlugins\Video\Video $object */
    $lang = \Idno\Core\Idno::site()->language();

    $address = is_string($object->video_url) ? $object->video_url : '';
if ($address === '' && !empty($vars['url']) && is_string($vars['url'])) {
    $address = $vars['url'];
}

?>
<?php echo $this->draw('entity/edit/header'); ?>
<form action="<?= htmlspecialchars($object->getURL()) ?>" method="post">

    <div class="idno-editor">
        <h4 class="idno-editor-heading">
            <?php
            if (empty($object->_id)) {
                echo $lang->_('New Video');
            } else {
                echo $lang->_('Edit Video');
                if ($object->getPublishStatus() === 'draft') {
                    echo ' <span class="idno-badge-draft">' . $lang->_('Draft') . '</span>';
                }
            }
            ?>
        </h4>

        <div class="idno-form-field">
            <label class="idno-label" for="video"><?= $lang->_('Video address') ?></label>
            <?= $this->__([
                'name' => 'video',
                'id' => 'video',
                'placeholder' => 'https://www.youtube.com/watch?v=...',
                'class' => 'idno-input video-url',
                'value' => $address,
                'required' => true,
                'autofocus' => empty($object->_id)
            ])->draw('forms/input/url') ?>
            <p class="idno-form-help">
                <?= htmlspecialchars($lang->_('Paste the address of a video on one of: %s.', [implode(', ', \IdnoPlugins\Video\VideoUrl::supportedServices())])) ?>
            </p>
        </div>

        <div class="idno-form-field">
            <label class="idno-label" for="title"><?= $lang->_('Title') ?></label>
            <?= $this->__([
                'name' => 'title',
                'id' => 'title',
                'placeholder' => $lang->_('Give it a title (optional)'),
                'value' => is_string($object->title) ? $object->title : '',
                'class' => 'idno-input video-title'
            ])->draw('forms/input/input') ?>
        </div>

        <?= $this->__([
            'name'        => 'body',
            'value'       => $object->getDescription(),
            'wordcount'   => false,
            'class'       => 'wysiwyg-short',
            'height'      => 250,
            'placeholder' => $lang->_('Describe your video...'),
            'label'       => $lang->_('Description')
        ])->draw('forms/input/richtext') ?>

        <?= $this->draw('entity/tags/input') ?>
        <?= $this->drawSyndication('video', $object->getPosseLinks()) ?>
        <?php if (empty($object->_id)) {
            echo $this->__(['name' => 'forward-to', 'value' => \Idno\Core\Idno::site()->config()->getDisplayURL() . 'content/all/'])->draw('forms/input/hidden');
        } ?>
        <?= $this->draw('content/extra') ?>
        <?= $this->draw('content/access') ?>

        <?= \Idno\Core\Idno::site()->actions()->signForm('/video/edit') ?>

        <div style="display:flex;flex-wrap:wrap;gap:0.5rem;margin-top:var(--spacing-section)">
            <button type="submit" class="idno-btn idno-btn-primary" name="publish_status" value="published">
                <?= $lang->_('Publish') ?>
            </button>
            <button type="submit" class="idno-btn idno-btn-ghost" name="publish_status" value="draft">
                <?= $lang->_('Save as Draft') ?>
            </button>
            <a href="<?= !empty($object->_id) ? htmlspecialchars($object->getDisplayURL()) : \Idno\Core\Idno::site()->config()->getDisplayURL() ?>" class="idno-btn idno-btn-ghost">
                <?= $lang->_('Cancel') ?>
            </a>
        </div>

    </div>

</form>
<?php echo $this->draw('entity/edit/footer');
