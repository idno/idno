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
<form action="<?php echo htmlspecialchars($object->getURL()) ?>" method="post">

    <div class="row">

        <div class="col-md-8 col-md-offset-2 edit-pane">
            <h4>
                <?php
                if (empty($object->_id)) {
                    echo $lang->_('New Video');
                } else {
                    echo $lang->_('Edit Video');
                }
                ?>
            </h4>

            <div class="content-form">
                <label for="video"><?php echo $lang->_('Video address'); ?></label>
                <?php echo $this->__([
                    'name' => 'video',
                    'id' => 'video',
                    'placeholder' => 'https://www.youtube.com/watch?v=...',
                    'class' => 'form-control video-url',
                    'value' => $address,
                    'required' => true
                ])->draw('forms/input/url'); ?>
                <p class="help-block">
                    <?php echo htmlspecialchars($lang->_('Paste the address of a video on one of: %s.', [implode(', ', \IdnoPlugins\Video\VideoUrl::supportedServices())])); ?>
                </p>

                <label for="title"><?php echo $lang->_('Title'); ?></label>
                <?php echo $this->__([
                    'name' => 'title',
                    'id' => 'title',
                    'placeholder' => $lang->_('Give it a title (optional)'),
                    'value' => is_string($object->title) ? $object->title : '',
                    'class' => 'form-control video-title'
                ])->draw('forms/input/input'); ?>

                <?php echo $this->__([
                    'name'        => 'body',
                    'value'       => $object->getDescription(),
                    'wordcount'   => false,
                    'class'       => 'wysiwyg-short',
                    'height'      => 250,
                    'placeholder' => $lang->_('Describe your video...'),
                    'label'       => $lang->_('Description')
                ])->draw('forms/input/richtext') ?>
            </div>
            <?php echo $this->draw('entity/tags/input'); ?>
            <?php echo $this->drawSyndication('video', $object->getPosseLinks()); ?>
            <?php if (empty($object->_id)) {
                echo $this->__(['name' => 'forward-to', 'value' => \Idno\Core\Idno::site()->config()->getDisplayURL() . 'content/all/'])->draw('forms/input/hidden');
            } ?>
            <?php echo $this->draw('content/extra'); ?>
            <?php echo $this->draw('content/access'); ?>

            <p class="button-bar">
                <?php echo \Idno\Core\Idno::site()->actions()->signForm('/video/edit') ?>
                <input type="button" class="btn btn-cancel" value="<?php echo $lang->_('Cancel'); ?>" onclick="hideContentCreateForm();"/>
                <input type="submit" class="btn btn-primary" value="<?php echo $lang->_('Save'); ?>"/>
            </p>
        </div>

    </div>

</form>
<?php echo $this->draw('entity/edit/footer');
