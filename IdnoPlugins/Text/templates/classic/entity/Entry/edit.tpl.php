<?php echo $this->draw('entity/edit/header'); ?>
<?php
    $autosave = new \Idno\Core\Autosave();
if (!empty($vars['object']->body)) {
    $body = $vars['object']->body;
} else {
    $body = '';
}
if (!empty($vars['object']->title)) {
    $title = $vars['object']->title;
} else {
    $title = '';
}
if (!empty($vars['object']->short_description)) {
    $subtitle = $vars['object']->short_description;
} else {
    $subtitle = '';
}
if (!empty($vars['object'])) {
    $object = $vars['object'];
} else {
    $object = false;
}
    $unique_id = 'body'.rand(0, 9999);

    /* @var \Idno\Core\Template $this */

?>
<?php
if (!empty($vars['object']->inreplyto)) {
    if (!is_array($vars['object']->inreplyto)) {
        $vars['object']->inreplyto = array($vars['object']->inreplyto);
    }
} else {
    $vars['object']->inreplyto = array();
}
if (!empty($vars['url'])) {
    $vars['object']->inreplyto = array($vars['url']);
}
?>
    <form action="<?php echo $vars['object']->getURL() ?>" method="post">

        <div class="row">

            <div class="col-md-8 col-md-offset-2 edit-pane">


                <?php

                if (empty($vars['object']->_id)) {

                    ?>
                        <h4><?php echo \Idno\Core\Idno::site()->language()->_('New Post'); ?></h4>
                    <?php

                } else {

                    ?>
                        <h4><?php echo \Idno\Core\Idno::site()->language()->_('Edit Post'); ?></h4>
                    <?php

                }

                ?>

                <div class="content-form">
                    <label for="title"><?php echo \Idno\Core\Idno::site()->language()->_('Title'); ?></label>
                    <?php echo $this->__(['name' => 'title', 'placeholder' => \Idno\Core\Idno::site()->language()->_('Give it a title'), 'id' => 'title', 'value' => $title, 'required' => true, 'class' => 'form-control'])->draw('forms/input/input'); ?>
                </div>
                        
                <div class="content-form">
                    <label for="subtitle"><?php echo \Idno\Core\Idno::site()->language()->_('Subtitle'); ?></label>
                    <?php echo $this->__(['name' => 'subtitle', 'placeholder' => \Idno\Core\Idno::site()->language()->_('Optional sub title for this post'), 'id' => 'subtitle', 'value' => $subtitle, 'class' => 'form-control'])->draw('forms/input/input'); ?>
                </div>

                <?php echo $this->__([
                    'name' => 'body',
                    'unique_id' => $unique_id,
                    'value' => $body,
                    'object' => $object,
                    'wordcount' => true,
                    'required' => true
                ])->draw('forms/input/richtext')?>
                <?php echo $this->draw('entity/tags/input'); ?>
                    <?php echo $this->draw('content/unfurl');

                    // Set focus so you can start typing straight away (on shares)
                    if (\Idno\Core\Idno::site()->currentPage()->getInput('share_url')) {
                        ?>
            <script>
                $(document).ready(function(){
                    var content = $('#title').val();
                    var len = content.length;
                    var element = $('#title');
                    $('#title').focus(function(){
                        $(this).prop('selectionStart', len);
                    });
                    $('#title').focus();
                });
            </script>
                        <?php
                    }
                    ?>

            <p>
                <small><a id="inreplyto-add" href="#"
                          onclick="$('#inreplyto').append('<span><input required type=&quot;url&quot; name=&quot;inreplyto[]&quot; value=&quot;&quot; placeholder=&quot;<?php echo addslashes(\Idno\Core\Idno::site()->language()->_('Add the URL that you\'re replying to')); ?>&quot; class=&quot;form-control&quot; onchange=&quot;adjust_content(this.value)&quot; /> <small><a href=&quot;#&quot; onclick=&quot;$(this).parent().parent().remove(); return false;&quot;><icon class=&quot;fa fa-times&quot;></icon> <?php echo \Idno\Core\Idno::site()->language()->esc_('Remove URL'); ?></a></small><br /></span>'); return false;"><i class="fa fa-reply"></i>
                        <?php echo \Idno\Core\Idno::site()->language()->_('Reply to a site'); ?></a></small>
            </p>


            <div id="inreplyto">
                <?php
                if (!empty($vars['object']->inreplyto)) {
                    foreach ($vars['object']->inreplyto as $inreplyto) {
                        ?>
                            <p>
                                <input type="url" name="inreplyto[]"
                                       placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('Add the URL that you\'re replying to'); ?>"
                                       class="form-control inreplyto" value="<?php echo htmlspecialchars($inreplyto) ?>" onchange="adjust_content(this.value)"/>
                                <small><a href="#"
                                          onclick="$(this).parent().parent().remove(); return false;"><i class="fa fa-times"></i>
                                      <?php echo \Idno\Core\Idno::site()->language()->_('Remove URL'); ?></a></small>
                            </p>
                        <?php
                    }
                }
                ?>
                </div>

                <?php echo $this->drawSyndication('article', $vars['object']->getPosseLinks()); ?>
                <?php if (empty($vars['object']->_id)) {
                    echo $this->__(['name' => 'forward-to', 'value' => \Idno\Core\Idno::site()->config()->getDisplayURL() . 'content/all/'])->draw('forms/input/hidden');
                } ?>

                <?php echo $this->draw('content/extra'); ?>
                <?php echo $this->draw('content/access'); ?>

                <p class="button-bar ">

                    <?php echo \Idno\Core\Idno::site()->actions()->signForm('/entry/edit') ?>
                    <input type="button" class="btn btn-cancel" value="<?php echo \Idno\Core\Idno::site()->language()->_('Cancel'); ?>" onclick="tinymce.EditorManager.execCommand('mceRemoveEditor',true, 'body'); hideContentCreateForm();"/>
                    <input type="submit" class="btn btn-primary" value="<?php echo \Idno\Core\Idno::site()->language()->_('Publish'); ?>"/>

                </p>

            </div>

        </div>
    </form>
<?php echo $this->draw('entity/edit/footer'); ?>
<script>

    function adjust_content(url) {
        var username = url.match(/https?:\/\/([a-z]+\.)?twitter\.com\/(#!\/)?@?([^\/]*)/)[3];
        if (username != null) {
            if ($('#title').val().search('@' + username) == -1) {
                $('#title').val('@' + username + ' ' + $('#title').val());
                //count_chars();
            }
        }
    }

    $(document).ready(function () {

        // Make in reply to a little less painful
        $("#inreplyto-add").on('dragenter', function(e) {
            var placeholder = '<?php echo addslashes(\Idno\Core\Idno::site()->language()->esc_('Add the URL that you\'re replying to')); ?>';
            e.stopPropagation();
            e.preventDefault();
            $('#inreplyto').append('<span><input required type="url" name="inreplyto[]" value="" placeholder="' + placeholder + '" class="form-control" onchange="adjust_content(this.value)" /> <small><a href="#" onclick="$(this).parent().parent().remove(); return false;"><icon class="fa fa-times"></icon> <?php echo \Idno\Core\Idno::site()->language()->esc_('Remove URL'); ?></a></small><br /></span>'); return false;
        });
    });

    $(document).ready(function(){
        // Autosave the title & body
        autoSave('entry', ['title', 'body'], {
          'body': '#<?php echo $unique_id?>',
        });
    });

</script>
