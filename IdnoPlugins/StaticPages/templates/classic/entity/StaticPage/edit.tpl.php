<?php echo $this->draw('entity/edit/header'); ?>
<?php

    /* @var \Idno\Core\Template $this */

if (!empty($vars['object'])) {
    $title       = $vars['object']->getTitle();
    $body        = $vars['object']->body;
    $forward_url = $vars['object']->forward_url;
    $hide_title  = $vars['object']->hide_title;
}

if ($title == 'Untitled') {
    $title = '';
}

?>
    <form action="<?php echo $vars['object']->getURL() ?>" method="post">

        <div class="row">

            <div class="col-md-8 col-md-offset-2 edit-pane">


                <?php

                if (empty($vars['object']->_id)) {

                    ?>
                        <h4><?php echo \Idno\Core\Idno::site()->language()->_('New Page'); ?></h4>
                    <?php

                } else {

                    ?>
                        <h4><?php echo \Idno\Core\Idno::site()->language()->_('Edit Page'); ?></h4>
                    <?php

                }

                ?>
                <p>
                    <label for="title">
                        <?php echo \Idno\Core\Idno::site()->language()->_('Title'); ?></label>
                    <input type="text" name="title" id="title" placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('Give it a title'); ?>"
                           value="<?php echo htmlspecialchars($title) ?>" class="form-control"/>
                </p>



                <?php echo $this->__([
                    'name' => 'body',
                    'value' => $vars['object']->body,
                    'wordcount' => false,
                    'class' => 'wysiwyg',
                    'height' => 500,
                    'placeholder' => \Idno\Core\Idno::site()->language()->_('Share something brilliant...'),
                    'label' => \Idno\Core\Idno::site()->language()->_('Body')
                ])->draw('forms/input/richtext')?>

                <?php echo $this->draw('entity/tags/input'); ?>

                <div class="page-cat">
                    <label>
                        <?php echo \Idno\Core\Idno::site()->language()->_('Parent category'); ?></label><br>
                    <select name="category" class="selectpicker">
                        <option <?php
                        if ($vars['category'] == 'No Category') {
                            echo 'selected';
                        } ?>><?php echo \Idno\Core\Idno::site()->language()->_('No Category'); ?>
                        </option>
                        <?php

                        if (!empty($vars['categories'])) {
                            foreach ($vars['categories'] as $category) {

                                ?>
                                    <option <?php
                                    if ($category == $vars['category']) {
                                        echo 'selected';
                                    } ?>><?php echo htmlspecialchars($category) ?></option>
                                <?php

                            }
                        }

                        ?>
                    </select>

                </div>

                <p id="show-options">
                    <small><a href="#" onclick="$('#moreoptions').toggle(); $('#show-options').hide(); return false;"><i
                                class="fa fa-plus"></i>
                            <?php echo \Idno\Core\Idno::site()->language()->_('Show advanced options'); ?></a></small>
                </p>
                <div id="moreoptions" <?php
                if (empty($hide_title) && empty($forward_url)) {
                    ?>
                        style="display:none"
                    <?php
                }
                ?>>

                    <p id="hide-options">
                        <small><a href="#"
                                  onclick="$('#moreoptions').toggle(); $('#show-options').show(); return false;"><i
                                    class="fa fa-minus"></i>
                                <?php echo \Idno\Core\Idno::site()->language()->_('Hide advanced options'); ?></a></small>
                    </p>

                    <div>
                        <p>
                            <label for="forward_url">
                                <?php echo \Idno\Core\Idno::site()->language()->_('Forward URL'); ?></label>
                            <input type="text" name="forward_url" id="forward_url"
                                   placeholder="<?php echo \Idno\Core\Idno::site()->language()->_('Website to forward users to'); ?>"
                                   value="<?php echo htmlspecialchars($forward_url) ?>" class="form-control"/>
                            <small><?php echo \Idno\Core\Idno::site()->language()->_('Most of the time, you should leave this blank. Include a URL here if you want users to be forwarded to an external page instead of displaying page content.'); ?></small>
                        </p>
                    </div>
                    <p style="margin-bottom: 20px">
                        <strong><?php echo \Idno\Core\Idno::site()->language()->_('Show the page title as a heading?'); ?></strong><br>
                        <label class="radio-inline">
                            <input type="radio" name="hide_title" id="title-heading" value="0" <?php

                            if (empty($hide_title)) {
                                echo 'checked';
                            }

                            ?>>
                            Yes
                        </label>
                        <label class="radio-inline">
                            <input type="radio" name="hide_title" id="title-heading" value="1" <?php

                            if (!empty($hide_title)) {
                                echo 'checked';
                            }

                            ?>>
                            No
                        </label>
                    </p>

                    <div class="page-cat">


                    </div>

                </div>

                <?php echo $this->draw('content/extra'); ?>
                <?php echo $this->draw('content/access'); ?>

                <p class="button-bar " style="text-align: right">
                    <?php echo \Idno\Core\Idno::site()->actions()->signForm('/staticpages/edit') ?>
                    <input type="button" class="btn btn-cancel" value="<?php echo \Idno\Core\Idno::site()->language()->_('Cancel'); ?>" onclick="hideContentCreateForm();"/>
                    <input type="submit" class="btn btn-primary" value="<?php echo \Idno\Core\Idno::site()->language()->_('Publish'); ?>"/>
                </p>

            </div>

        </div>
    </form>
    <script>

        /*function postForm() {
         var content = $('textarea[name="body"]').html($('#body').html());
         console.log(content);
         return content;
         }*/

        $(document).ready(function () {
            makeRich('#body');
        })
        ;

        function makeRich(container) {
            $(container).tinymce({
                selector: 'textarea',
                theme: 'modern',
                skin: 'light',
                statusbar: false,
                menubar: false,
                toolbar: 'styleselect | bold italic | link image | blockquote bullist numlist | alignleft aligncenter alignright | code',
                plugins: 'code link image autoresize',
                relative_urls: false,
                remove_script_host: false,
                convert_urls: true,
                file_picker_callback: function (callback, value, meta) {
                    filePickerDialog(callback, value, meta);
                }
            });
        }

        function filePickerDialog(callback, value, meta) {
            tinymce.activeEditor.windowManager.open({
                title: 'File Manager',
                url: '<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>filepicker/?type=' + meta.filetype,
                width: 650,
                height: 550
            }, {
                oninsert: function (url) {
                    callback(url);
                }
            });
        }

        //$('.selectpicker').selectpicker();

    </script>
<?php echo $this->draw('entity/edit/footer');
