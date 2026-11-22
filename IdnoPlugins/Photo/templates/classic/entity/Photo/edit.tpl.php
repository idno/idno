<?php

    $attachments = $vars['object']->getAttachments(); // TODO: Handle multiple
    $multiple = false;
    $num_pics = count($attachments);
    if ($num_pics > 1)
        $multiple = true;
    $cnt = 0;
?>

<?php echo $this->draw('entity/edit/header'); ?>
    <form action="<?php echo $vars['object']->getURL() ?>" method="post" enctype="multipart/form-data">

        <div class="row">

            <div class="col-md-8 col-md-offset-2 edit-pane">

                <h4>
                    <?php

                    if (empty($vars['object']->_id)) {
                        ?><?php echo \Idno\Core\Idno::site()->language()->_('New Photo'); ?><?php
                    } else {
                        ?><?php echo \Idno\Core\Idno::site()->language()->_('Edit Photo'); ?><?php
                    }

                    ?>
                </h4>
                
                <div class="photo-files <?php if ($multiple) echo "multiple-images"; ?>" data-num-pics="<?php echo $num_pics; ?>">
                    <?php for ($n = 0; $n < 10; $n++) { ?>
                        <div class="image-file" data-number="<?php echo $n; ?>" style="<?php if ($n > 0) echo 'display: none;'; ?>">
                            <?php echo $this->__([
                                'name' => 'photo[]',
                                'hide-existing' => $n > 0,
                                'hide-delete' => $n > 0
                            ])->draw('forms/input/image-file'); ?>
                        </div>
                    <?php } ?>
                </div>

                <div id="photo-details">

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
                        'class' => 'wysiwyg-short',
                        'height' => 100,
                        'placeholder' => \Idno\Core\Idno::site()->language()->_('Describe your photo'),
                        'label' => \Idno\Core\Idno::site()->language()->_('Description')
                    ])->draw('forms/input/richtext')?>

                    <?php echo $this->draw('entity/tags/input'); ?>

                </div>
                
                <?php echo $this->drawSyndication('image', $vars['object']->getPosseLinks()); ?>
                <?php if (empty($vars['object']->_id)) {
                    echo $this->__(['name' => 'forward-to', 'value' => \Idno\Core\Idno::site()->config()->getDisplayURL() . 'content/all/'])->draw('forms/input/hidden');
                } ?>
                <?php echo $this->draw('content/extra'); ?>
                <?php echo $this->draw('content/access'); ?>
                <p class="button-bar ">
                    <?php echo \Idno\Core\Idno::site()->actions()->signForm('/photo/edit') ?>
                    <input type="button" class="btn btn-cancel" value="<?php echo \Idno\Core\Idno::site()->language()->_('Cancel'); ?>" onclick="hideContentCreateForm();"/>
                    <input type="submit" class="btn btn-primary" value="<?php echo \Idno\Core\Idno::site()->language()->_('Publish'); ?>"/>
                </p>
            </div>

        </div>
    </form>
<script>
    $(document).ready(function () {
        $('.photo-files input').change(function(){
            var number = parseInt($(this).closest('div.image-file').attr('data-number'));
            number = number + 1;
            console.log("Showing item " + number);
            $('.photo-files .image-file[data-number='+number.toString()+']').show();
        });
    } );
</script>    

<?php echo $this->draw('entity/edit/footer');
