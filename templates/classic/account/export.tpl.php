<div class="row">

    <div class="col-md-10 col-md-offset-1">

        <?php echo $this->draw('account/menu') ?>

        <h1>
            <?php echo \Idno\Core\Idno::site()->language()->_('Export your data'); ?>
        </h1>



        <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>account/export/rss" method="post">

            <!--<h2>
                Export to WordPress
            </h2>-->
            <p class="explanation">
                <?php echo \Idno\Core\Idno::site()->language()->_("You can download an RSS version of everything you've posted on this site. This file is suitable for importing into content management systems like WordPress, or another Idno site."); ?>
            </p>

            <div class="row">
                <div class="col-md-3">
                    <p><label class="control-label" for="allposts"><strong><?php echo \Idno\Core\Idno::site()->language()->_('Include private posts?'); ?></strong></label></p>
                </div>
                <div class="config-toggle col-md-2">
                    <input type="checkbox" data-toggle="toggle" data-onstyle="info"
                           data-on="<?php echo \Idno\Core\Idno::site()->language()->_('Yes'); ?>"
                           data-off="<?php echo \Idno\Core\Idno::site()->language()->_('No'); ?>"
                           name="allposts" id="allposts"
                           value="1">
                </div>
                <div class="col-md-7">
                    <p class="config-desc"><?php echo \Idno\Core\Idno::site()->language()->_('Platforms like WordPress may assume that all your posts should be displayed publicly. In order to protect your privacy, you may wish to just download your public posts.'); ?></p>
                </div>
            </div>
            <div class="">
                <button type="submit" class="btn btn-primary"><?php echo \Idno\Core\Idno::site()->language()->_('Download RSS Feed'); ?></button>
                <?php echo \Idno\Core\Idno::site()->actions()->signForm('/account/export/rss') ?>
            </div>

        </form>

        <hr>

        <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>account/export/wxr" method="post">

            <h2>
                <?php echo \Idno\Core\Idno::site()->language()->_('Export as WordPress (WXR)'); ?>
            </h2>
            <p class="explanation">
                <?php echo \Idno\Core\Idno::site()->language()->_("Download a WordPress eXtended RSS (WXR) file. This format includes additional metadata such as post dates, slugs, and comments, and is the standard format for importing into WordPress."); ?>
            </p>

            <div class="row">
                <div class="col-md-3">
                    <p><label class="control-label" for="allposts_wxr"><strong><?php echo \Idno\Core\Idno::site()->language()->_('Include private posts?'); ?></strong></label></p>
                </div>
                <div class="config-toggle col-md-2">
                    <input type="checkbox" data-toggle="toggle" data-onstyle="info"
                           data-on="<?php echo \Idno\Core\Idno::site()->language()->_('Yes'); ?>"
                           data-off="<?php echo \Idno\Core\Idno::site()->language()->_('No'); ?>"
                           name="allposts" id="allposts_wxr"
                           value="1">
                </div>
                <div class="col-md-7">
                    <p class="config-desc"><?php echo \Idno\Core\Idno::site()->language()->_('Platforms like WordPress may assume that all your posts should be displayed publicly. In order to protect your privacy, you may wish to just download your public posts.'); ?></p>
                </div>
            </div>
            <div class="">
                <button type="submit" class="btn btn-primary"><?php echo \Idno\Core\Idno::site()->language()->_('Download WXR File'); ?></button>
                <?php echo \Idno\Core\Idno::site()->actions()->signForm('/account/export/wxr') ?>
            </div>

        </form>

    </div>

</div>
