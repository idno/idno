<?php echo $this->draw('entity/edit/header');?>
<form action="<?php echo $vars['object']->getURL()?>" method="post" enctype="multipart/form-data">

    <div class="row">

        <div class="col-md-8 col-md-offset-2 edit-pane">

                <h4>

                <?php

                if (empty($vars['object']->_id)) {
                    ?><?php echo \Idno\Core\Idno::site()->language()->_('New Audio'); ?><?php
                } else {
                    ?><?php echo \Idno\Core\Idno::site()->language()->_('Edit Audio'); ?><?php
                }

                ?>
                </h4>

            <p>

                <label>
                    <span class="btn btn-primary btn-file">
                        <i class="fa fa-play-circle"></i> <span id="media-filename"><?php
                        if (empty($vars['object']->_id)) {
                            ?><?php echo \Idno\Core\Idno::site()->language()->_('Upload audio'); ?><?php
                        } else {
                            ?><?php echo \Idno\Core\Idno::site()->language()->_('Choose different audio'); ?><?php
                        } ?></span>
                        <?php echo $this->__([
                            'name' => 'media',
                            'id' => 'media',
                            'accept' => 'audio/*;video/*;capture=microphone',
                            'onchange' => "$('#media-filename').html($(this).val())",
                        'class' => 'col-md-9'])->draw('forms/input/file'); ?>
                    </span>
                </label>

            </p>
            <div class="content-form">
                <label for="title">
                    <?php echo \Idno\Core\Idno::site()->language()->_('Title'); ?></label>
                    <?php echo $this->__([
                        'name' => 'title',
                        'id' => 'title',
                        'placeholder' => \Idno\Core\Idno::site()->language()->_('Give it a title'),
                        'value' => $vars['object']->title,
                    'class' => 'form-control'])->draw('forms/input/input'); ?>

            </div>

            <?php echo $this->__([
                'name' => 'body',
                'value' => $vars['object']->body,
                'wordcount' => false,
                'height' => 250,
                'class' => 'wysiwyg-short',
                'placeholder' => \Idno\Core\Idno::site()->language()->_('Describe your audio'),
                'label' => \Idno\Core\Idno::site()->language()->_('Description'),
            ])->draw('forms/input/richtext')?>
            <?php echo $this->draw('entity/tags/input');?>
            <?php echo $this->drawSyndication('media', $vars['object']->getPosseLinks()); ?>
            <?php if (empty($vars['object']->_id)) {
                echo $this->__(['name' => 'forward-to', 'value' => \Idno\Core\Idno::site()->config()->getDisplayURL() . 'content/all/'])->draw('forms/input/hidden');
            } ?>
            <?php echo $this->draw('content/extra'); ?>
            <?php echo $this->draw('content/access'); ?>
            <p class="button-bar ">
                <?php echo \Idno\Core\Idno::site()->actions()->signForm('/media/edit') ?>
                <input type="button" class="btn btn-cancel" value="<?php echo \Idno\Core\Idno::site()->language()->_('Cancel'); ?>" onclick="hideContentCreateForm();" />
                <input type="submit" class="btn btn-primary" value="<?php echo \Idno\Core\Idno::site()->language()->_('Publish'); ?>" />

            </p>
        </div>

    </div>
</form>
<?php echo $this->draw('entity/edit/footer');
