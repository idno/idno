<div class="row">
    <div class="col-md-10 col-md-offset-1">
        <?php

            echo $this->draw('admin/menu');

        ?>
        <h1>
            <?php echo \Idno\Core\Idno::site()->language()->_('Export content'); ?>
        </h1>

    </div>
</div>
<div class="row">
    <div class="col-md-10 col-md-offset-1">
        <p class="explanation">
            <?php echo \Idno\Core\Idno::site()->language()->_('Export your published Idno content as an RSS file. You can import this file into WordPress, or into another Idno site by choosing WordPress on the import page.'); ?>
        </p>
        <h3>
            <?php echo \Idno\Core\Idno::site()->language()->_('Generate RSS file'); ?>
        </h3>
        <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>admin/export/rss" method="post">
            <div class="row">
                <div class="col-md-3">
                    <p><label class="control-label" for="allposts"><strong><?php echo \Idno\Core\Idno::site()->language()->_('Include private posts?'); ?></strong></label></p>
                </div>
                <div class="config-toggle col-md-3">
                    <input type="checkbox" data-toggle="toggle" data-onstyle="info"
                           data-on="<?php echo \Idno\Core\Idno::site()->language()->_('Yes'); ?>"
                           data-off="<?php echo \Idno\Core\Idno::site()->language()->_('No'); ?>"
                           name="allposts" id="allposts"
                           value="1">
                </div>
                <div class="col-md-6">
                    <p class="config-desc"><?php echo \Idno\Core\Idno::site()->language()->_('Some platforms may assume that your content should be displayed publicly. To protect your privacy, you may wish to only download your public content.'); ?> <?php echo \Idno\Core\Idno::site()->language()->_("As an administrator, including private posts includes every member's private posts and drafts too. Private posts and drafts are marked as such in the file."); ?></p>
                </div>
            </div>
            <p>
                <input type="submit" class="btn btn-primary" value="<?php echo \Idno\Core\Idno::site()->language()->_('Download file'); ?>">
            </p>
            <?php

                echo \Idno\Core\Idno::site()->actions()->signForm(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/export/rss');

            ?>
        </form>

        <hr>

        <h3>
            <?php echo \Idno\Core\Idno::site()->language()->_('Generate WordPress (WXR) file'); ?>
        </h3>
        <p class="explanation">
            <?php echo \Idno\Core\Idno::site()->language()->_('Export your content as a WordPress eXtended RSS (WXR) file. This format includes additional metadata such as post dates, slugs, and comments, and is the standard format for importing into WordPress.'); ?>
        </p>
        <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>admin/export/wxr" method="post">
            <div class="row">
                <div class="col-md-3">
                    <p><label class="control-label" for="allposts_wxr"><strong><?php echo \Idno\Core\Idno::site()->language()->_('Include private posts?'); ?></strong></label></p>
                </div>
                <div class="config-toggle col-md-3">
                    <input type="checkbox" data-toggle="toggle" data-onstyle="info"
                           data-on="<?php echo \Idno\Core\Idno::site()->language()->_('Yes'); ?>"
                           data-off="<?php echo \Idno\Core\Idno::site()->language()->_('No'); ?>"
                           name="allposts" id="allposts_wxr"
                           value="1">
                </div>
                <div class="col-md-6">
                    <p class="config-desc"><?php echo \Idno\Core\Idno::site()->language()->_('Some platforms may assume that your content should be displayed publicly. To protect your privacy, you may wish to only download your public content.'); ?> <?php echo \Idno\Core\Idno::site()->language()->_("As an administrator, including private posts includes every member's private posts and drafts too. Private posts and drafts are marked as such in the file."); ?></p>
                </div>
            </div>
            <p>
                <input type="submit" class="btn btn-primary" value="<?php echo \Idno\Core\Idno::site()->language()->_('Download WXR file'); ?>">
            </p>
            <?php

                echo \Idno\Core\Idno::site()->actions()->signForm(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/export/wxr');

            ?>
        </form>

        <hr>

        <h3>
            <?php echo \Idno\Core\Idno::site()->language()->_('Full archive'); ?>
        </h3>
        <p class="explanation">
            <?php echo \Idno\Core\Idno::site()->language()->_("A complete copy of your site as one zip file: every post and its files, the settings, and the records of the database. It is what you restore your site from."); ?>
        </p>
        <div class="alert alert-warning" role="alert">
            <strong><?php echo \Idno\Core\Idno::site()->language()->_('Keep it as carefully as a database backup.'); ?></strong>
            <?php echo \Idno\Core\Idno::site()->language()->_("The archive contains every account's password hash and the private keys that sign each user's posts to the fediverse: whoever has the archive can sign as any user. It also holds every private post. Only administrators can download it, and it is removed from the site when you make a new one and after a week. The settings in it (config.json) are left out by the name of the setting: review config.json before you share the archive."); ?>
        </div>
        <?php if (isset($vars['zip_available']) && !$vars['zip_available']) { ?>
            <div class="alert alert-danger" role="alert">
                <?php echo \Idno\Core\Idno::site()->language()->_("The full archive needs the PHP zip extension, which this server does not have. Ask whoever runs the server to enable it (the zip extension of PHP), then come back to this page."); ?>
            </div>
        <?php } elseif (!empty($vars['export_in_progress'])) { ?>
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_('An archive of your data is being created. Please check again in a few minutes: we will email you when it is ready.'); ?>
            </p>
        <?php } else { ?>
            <?php if (!empty($vars['export_file_id'])) { ?>
                <p style="text-align: left">
                    <a class="btn btn-primary" href="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>file/<?php echo urlencode((string)$vars['export_file_id'])?>/<?php echo urlencode((string)($vars['export_filename'] ?? 'archive.zip'))?>"><?php echo \Idno\Core\Idno::site()->language()->_('Download your data'); ?></a><br>
                    <small><?php echo \Idno\Core\Idno::site()->language()->_('Generated'); ?> <time class="dt-published"
                           datetime="<?php echo date('c', (int)$vars['export_last_requested']) ?>"><?php echo date('c', (int)$vars['export_last_requested']) ?></time></small>
                </p>
                <p>
                    <?php echo \Idno\Core\Idno::site()->language()->_('You can also make a new copy of your data archive. It replaces this one.'); ?>
                </p>
            <?php } ?>
            <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>admin/export/generate/" method="post">
                <p>
                    <input type="submit" class="btn btn-primary" value="<?php echo !empty($vars['export_file_id']) ? \Idno\Core\Idno::site()->language()->_('Re-archive your data') : \Idno\Core\Idno::site()->language()->_('Create archive'); ?>">
                    <?php

                        echo \Idno\Core\Idno::site()->actions()->signForm(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/export/generate');

                    ?>
                </p>
            </form>
            <p class="config-desc">
                <?php echo \Idno\Core\Idno::site()->language()->_("It may take a while to generate your archive. You can leave this page while it's working. Once the archive is complete we will email you, and you'll be able to download it right here."); ?>
            </p>
        <?php } ?>

    </div>
</div>
