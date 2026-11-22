<h1 class="idno-admin-page-title"><?= \Idno\Core\Idno::site()->language()->_('Export') ?></h1>
    <p class="idno-admin-description">
        <?= \Idno\Core\Idno::site()->language()->_('Export your published Idno content as an RSS file. You can import this file into WordPress, or into another Idno site by choosing WordPress on the import page.') ?>
    </p>

    <div class="idno-admin-card">
        <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Generate RSS file') ?></h2>
        <form action="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>admin/export/rss" method="post">
            <div class="idno-form-group">
                <div class="idno-checkbox-group">
                    <input type="checkbox" name="allposts" id="allposts" value="1">
                    <label for="allposts"><?= \Idno\Core\Idno::site()->language()->_('Include private posts?') ?></label>
                </div>
                <p class="idno-form-help"><?= \Idno\Core\Idno::site()->language()->_('Some platforms may assume that your content should be displayed publicly. To protect your privacy, you may wish to only download your public content.') ?> <?= \Idno\Core\Idno::site()->language()->_("As an administrator, including private posts includes every member's private posts and drafts too. Private posts and drafts are marked as such in the file.") ?></p>
            </div>
            <div class="idno-form-group">
                <button type="submit" class="idno-btn idno-btn-primary"><?= \Idno\Core\Idno::site()->language()->_('Download file') ?></button>
            </div>
            <?= \Idno\Core\Idno::site()->actions()->signForm(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/export/rss') ?>
        </form>
    </div>

    <div class="idno-admin-card">
        <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Generate WordPress (WXR) file') ?></h2>
        <p>
            <?= \Idno\Core\Idno::site()->language()->_('Export your content as a WordPress eXtended RSS (WXR) file. This format includes additional metadata such as post dates, slugs, and comments, and is the standard format for importing into WordPress.') ?>
        </p>
        <form action="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>admin/export/wxr" method="post">
            <div class="idno-form-group">
                <div class="idno-checkbox-group">
                    <input type="checkbox" name="allposts" id="allposts_wxr" value="1">
                    <label for="allposts_wxr"><?= \Idno\Core\Idno::site()->language()->_('Include private posts?') ?></label>
                </div>
                <p class="idno-form-help"><?= \Idno\Core\Idno::site()->language()->_('Some platforms may assume that your content should be displayed publicly. To protect your privacy, you may wish to only download your public content.') ?> <?= \Idno\Core\Idno::site()->language()->_("As an administrator, including private posts includes every member's private posts and drafts too. Private posts and drafts are marked as such in the file.") ?></p>
            </div>
            <div class="idno-form-group">
                <button type="submit" class="idno-btn idno-btn-primary"><?= \Idno\Core\Idno::site()->language()->_('Download WXR file') ?></button>
            </div>
            <?= \Idno\Core\Idno::site()->actions()->signForm(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/export/wxr') ?>
        </form>
    </div>

    <div class="idno-admin-card">
        <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Full archive') ?></h2>
        <p>
            <?= \Idno\Core\Idno::site()->language()->_("A complete copy of your site as one zip file: every post and its files, the settings, and the records of the database. It is what you restore your site from.") ?>
        </p>
        <p class="idno-form-help" role="alert">
            <strong><?= \Idno\Core\Idno::site()->language()->_('Keep it as carefully as a database backup.') ?></strong>
            <?= \Idno\Core\Idno::site()->language()->_("The archive contains every account's password hash and the private keys that sign each user's posts to the fediverse: whoever has the archive can sign as any user. It also holds every private post. Only administrators can download it, and it is removed from the site when you make a new one and after a week. The settings in it (config.json) are left out by the name of the setting: review config.json before you share the archive.") ?>
        </p>
        <?php if (isset($vars['zip_available']) && !$vars['zip_available']) { ?>
            <p class="idno-form-help" role="alert">
                <?= \Idno\Core\Idno::site()->language()->_("The full archive needs the PHP zip extension, which this server does not have. Ask whoever runs the server to enable it (the zip extension of PHP), then come back to this page.") ?>
            </p>
        <?php } elseif (!empty($vars['export_in_progress'])) { ?>
            <p>
                <?= \Idno\Core\Idno::site()->language()->_('An archive of your data is being created. Please check again in a few minutes: we will email you when it is ready.') ?>
            </p>
        <?php } else { ?>
            <?php if (!empty($vars['export_file_id'])) { ?>
                <p>
                    <a class="idno-btn idno-btn-primary" href="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>file/<?= urlencode((string)$vars['export_file_id']) ?>/<?= urlencode((string)($vars['export_filename'] ?? 'archive.zip')) ?>"><?= \Idno\Core\Idno::site()->language()->_('Download your data') ?></a><br>
                    <small><?= \Idno\Core\Idno::site()->language()->_('Generated') ?> <time class="dt-published" datetime="<?= date('c', (int)$vars['export_last_requested']) ?>"><?= date('c', (int)$vars['export_last_requested']) ?></time></small>
                </p>
                <p>
                    <?= \Idno\Core\Idno::site()->language()->_('You can also make a new copy of your data archive. It replaces this one.') ?>
                </p>
            <?php } ?>
            <form action="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>admin/export/generate/" method="post">
                <div class="idno-form-group">
                    <button type="submit" class="idno-btn idno-btn-primary"><?= !empty($vars['export_file_id']) ? \Idno\Core\Idno::site()->language()->_('Re-archive your data') : \Idno\Core\Idno::site()->language()->_('Create archive') ?></button>
                </div>
                <?= \Idno\Core\Idno::site()->actions()->signForm(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/export/generate') ?>
            </form>
            <p class="idno-form-help">
                <?= \Idno\Core\Idno::site()->language()->_("It may take a while to generate your archive. You can leave this page while it's working. Once the archive is complete we will email you, and you'll be able to download it right here.") ?>
            </p>
        <?php } ?>
    </div>
