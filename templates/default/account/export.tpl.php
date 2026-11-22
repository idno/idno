<h1 class="idno-admin-page-title">
    <?php echo \Idno\Core\Idno::site()->language()->_('Export your data'); ?>
</h1>

<div class="idno-admin-card">
    <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>account/export/rss" method="post">

        <h2><?php echo \Idno\Core\Idno::site()->language()->_('Export as RSS'); ?></h2>
        <p class="idno-admin-description">
            <?php echo \Idno\Core\Idno::site()->language()->_("You can download an RSS version of everything you've posted on this site. This file is suitable for importing into content management systems like WordPress, or another Idno site."); ?>
        </p>

        <div class="idno-form-group">
            <label>
                <input type="checkbox" name="allposts" id="allposts" value="1">
                <?php echo \Idno\Core\Idno::site()->language()->_('Include private posts?'); ?>
            </label>
            <p class="idno-form-help"><?php echo \Idno\Core\Idno::site()->language()->_('Platforms like WordPress may assume that all your posts should be displayed publicly. In order to protect your privacy, you may wish to just download your public posts.'); ?></p>
        </div>

        <div class="idno-form-actions">
            <button type="submit" class="idno-btn-primary"><?php echo \Idno\Core\Idno::site()->language()->_('Download RSS Feed'); ?></button>
        </div>

        <?php echo \Idno\Core\Idno::site()->actions()->signForm('/account/export/rss') ?>
    </form>
</div>

<div class="idno-admin-card" style="margin-top: 1.5rem;">
    <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>account/export/wxr" method="post">

        <h2><?php echo \Idno\Core\Idno::site()->language()->_('Export as WordPress (WXR)'); ?></h2>
        <p class="idno-admin-description">
            <?php echo \Idno\Core\Idno::site()->language()->_("Download a WordPress eXtended RSS (WXR) file. This format includes additional metadata such as post dates, slugs, and comments, and is the standard format for importing into WordPress."); ?>
        </p>

        <div class="idno-form-group">
            <label>
                <input type="checkbox" name="allposts" id="allposts_wxr" value="1">
                <?php echo \Idno\Core\Idno::site()->language()->_('Include private posts?'); ?>
            </label>
            <p class="idno-form-help"><?php echo \Idno\Core\Idno::site()->language()->_('Platforms like WordPress may assume that all your posts should be displayed publicly. In order to protect your privacy, you may wish to just download your public posts.'); ?></p>
        </div>

        <div class="idno-form-actions">
            <button type="submit" class="idno-btn-primary"><?php echo \Idno\Core\Idno::site()->language()->_('Download WXR File'); ?></button>
        </div>

        <?php echo \Idno\Core\Idno::site()->actions()->signForm('/account/export/wxr') ?>
    </form>
</div>
