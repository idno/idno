<?php echo $this->draw('entity/edit/header'); ?>
<form action="<?php echo $vars['object']->getURL() ?>" method="post">

    <div class="row">

        <div class="col-md-8 col-md-offset-2 edit-pane">
            <h4>
                <?php

                if (empty($vars['object']->_id)) {
                    ?><?php echo \Idno\Core\Idno::site()->language()->_('New Bookmark'); ?><?php
                } else {
                    ?><?php echo \Idno\Core\Idno::site()->language()->_('Edit Bookmark'); ?><?php
                }

                ?>
            </h4>

            <div class="content-form">
                <label for="body">
                    <?php echo \Idno\Core\Idno::site()->language()->_('Link Address'); ?></label>
                <?php
                $value = "";
                if (empty($vars['url'])) {
                    $value = $vars['object']->body;
                } else {
                    $value = $vars['url'];
                }
                echo $this->__([
                    'name' => 'body',
                    'id' => 'body',
                    'placeholder' => "https://....",
                    'class' => "form-control bookmark-url",
                    'value' => $value,
                    'required' => true
                ])->draw('forms/input/url');
                ?>
                </label>
                <?php

                if (empty($vars['url'])) {

                    ?>

                    <div class="bookmark-spinner-container">
                        <?php echo $this->__(['class' => 'bookmark-title-spinner'])->draw('entity/edit/spinner'); ?>
                    </div>

                    <?php

                }

                ?>
                <div class="bookmark-title-container" for="title"
                        <?php
                        if (empty($vars['object']->pageTitle) && empty($vars['object']->_id) && (empty($vars['url']) && empty($vars['object']->body))) {
                            ?>style="display:none"<?php
                        } ?>>
                    <label for="title">
                        <?php echo \Idno\Core\Idno::site()->language()->_('Title'); ?><br/>
                    </label>
                    <?php echo $this->__([
                            'name' => 'title',
                            'id' => 'title',
                            'placeholder' => \Idno\Core\Idno::site()->language()->_('Page name'),
                            'value' => $vars['object']->pageTitle,
                            'required' => true,
                    'class' => 'form-control bookmark-title'])->draw('forms/input/input'); ?>

                </div>

                <?php echo $this->draw('content/unfurl'); ?>

                <?php echo $this->__([
                    'name'        => 'description',
                    'value'       => $vars['object']->description,
                    'wordcount'   => false,
                    'class'       => 'wysiwyg-short',
                    'height'      => 250,
                    'placeholder' => \Idno\Core\Idno::site()->language()->_('Add notes to your bookmark...'),
                    'label'       => \Idno\Core\Idno::site()->language()->_('Description')
                ])->draw('forms/input/richtext') ?>
            </div>
            <?php echo $this->draw('entity/tags/input'); ?>
            <?php echo $this->drawSyndication('bookmark', $vars['object']->getPosseLinks()); ?>
            <?php if (empty($vars['object']->_id)) {
                echo $this->__(['name' => 'forward-to', 'value' => \Idno\Core\Idno::site()->config()->getDisplayURL() . 'content/all/'])->draw('forms/input/hidden');
            } ?>
            <?php echo $this->draw('content/extra'); ?>
            <?php echo $this->draw('content/access'); ?>


            <p class="button-bar">
                <?php echo \Idno\Core\Idno::site()->actions()->signForm('/like/edit') ?>
                <input type="button" class="btn btn-cancel" value="<?php echo \Idno\Core\Idno::site()->language()->_('Cancel'); ?>" onclick="hideContentCreateForm();"/>
                <input type="submit" class="btn btn-primary" value="<?php echo \Idno\Core\Idno::site()->language()->_('Save'); ?>"/>

            </p>
        </div>

    </div>

</form>
<?php echo $this->draw('entity/edit/footer'); ?>
<script language="javascript">

    $(document).ready(function () {

        $('.bookmark-url').change(function () {

            if ($('bookmark-url').val() != "") {
                $('.bookmark-title-spinner').show();
                $.ajax({
                    dataType: "json",
                    url: "<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL(); ?>like/callback/",
                    data: {
                        url: $('.bookmark-url').val()
                    },
                    success: function (data) {
                        $('.bookmark-title').val(data.value);
                        $('.bookmark-spinner-container').html(" ");
                        $('.bookmark-title-container').show();

                        var unfurl = $('.bookmark-url').closest('form').find('.unfurl');
                        unfurl.attr('data-url', $('.bookmark-url').val());
                        Unfurl.unfurl(unfurl);

                    },
                    error: function () {
                        $('.bookmark-spinner-container').html(" ");
                        $('.bookmark-title-container').show();
                    }
                });
            }

        });

    })

</script>
