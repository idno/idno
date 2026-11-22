<div class="row">
    <div class="col-md-10 col-md-offset-1">
                <?php echo $this->draw('admin/menu'); ?>
        <h1>
            <?php echo \Idno\Core\Idno::site()->language()->_('Custom CSS'); ?>
        </h1>
        <div class="explanation">
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_('The site styles CSS editor lets you easily modify the visual style of your Idno site by overriding the default CSS. With Custom CSS, you have more control over the fonts, colors, and visual impact of your site.'); ?>
            </p>
        </div>
    </div>
</div>
<form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>admin/styles/" method="post" enctype="multipart/form-data">
    <div class="row">
        <div class="col-md-10 col-md-offset-1">
        <h2><?php echo \Idno\Core\Idno::site()->language()->_('Stylesheet editor'); ?></h2>
        </div>
    </div>
    <div class="row">
        <div class="col-md-10 col-md-offset-1">
            <p><?php echo \Idno\Core\Idno::site()->language()->_("Add your changes to Idno's core CSS below."); ?> </p>
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_("Do you have an existing stylesheet that you'd like to use? Import a CSS file from your computer."); ?>
                <span class="btn btn-primary btn-file upload">
                    <span id="css-filename"><?php echo \Idno\Core\Idno::site()->language()->_('Upload a stylesheet'); ?></span> <input type="file" name="cssfile" id="cssfile" class="col-md-9"/>
                    <input type="file" name="import" accept="text/css" id="cssfileinput" onchange="$('#css-filename').html($('#cssfileinput').val());"/>

                </span>
            </p>
            <textarea class="form-control" name="css" style="height: 15em; font-family: Courier, monospace"><?php

                    echo htmlspecialchars($vars['css']);

            ?></textarea>
            <?php echo \Idno\Core\Idno::site()->language()->_('You can also'); ?> <a href="<?php echo \Idno\Core\Idno::site()->config()->url ?>styles/site/"><?php echo \Idno\Core\Idno::site()->language()->_('download your stylesheet'); ?></a> <?php echo \Idno\Core\Idno::site()->language()->_('to work on it locally.'); ?>
        </div>
    </div>

    <div class="row">
        <div class="col-md-10 col-md-offset-1">
            <p>
                <input type="submit" class="btn btn-primary code" value="<?php echo \Idno\Core\Idno::site()->language()->_('Save stylesheet'); ?>"/>
                <?php echo \Idno\Core\Idno::site()->actions()->signForm(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/styles/') ?>
            </p>
        </div>
    </div>
</form>