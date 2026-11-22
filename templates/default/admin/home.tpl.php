<h1 class="idno-admin-page-title"><?= \Idno\Core\Idno::site()->language()->_('Site Settings') ?></h1>
    <?= $this->draw('admin/home/description') ?>

    <form action="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>admin/" class="admin" method="post">

        <div class="idno-admin-card">
            <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Site Details') ?></h2>

            <div class="idno-form-group">
                <label class="idno-label" for="name"><?= \Idno\Core\Idno::site()->language()->_('Site name') ?></label>
                <input type="text" id="name" name="title" class="idno-input"
                       placeholder="<?= \Idno\Core\Idno::site()->language()->_('Site name') ?>"
                       value="<?= htmlspecialchars(\Idno\Core\Idno::site()->config()->title) ?>">
                <p class="idno-form-help"><?= \Idno\Core\Idno::site()->language()->_('Give your site a name!') ?></p>
            </div>

            <div class="idno-form-group">
                <label class="idno-label" for="description"><?= \Idno\Core\Idno::site()->language()->_('Site summary') ?></label>
                <input type="text" id="description" name="description" class="idno-input"
                       placeholder="<?= \Idno\Core\Idno::site()->language()->_('Site description') ?>"
                       value="<?= htmlspecialchars(\Idno\Core\Idno::site()->config()->description) ?>">
                <p class="idno-form-help"><?= \Idno\Core\Idno::site()->language()->_("What's your site about?") ?></p>
            </div>

            <div class="idno-form-group">
                <label class="idno-label" for="homepage-title"><?= \Idno\Core\Idno::site()->language()->_('Homepage title') ?></label>
                <input type="text" id="homepage-title" name="homepagetitle" class="idno-input"
                       placeholder="<?= \Idno\Core\Idno::site()->language()->_('Homepage title') ?>"
                       value="<?= htmlspecialchars(\Idno\Core\Idno::site()->config()->homepagetitle) ?>">
            </div>

            <div class="idno-form-group">
                <label class="idno-label" for="items_per_page"><?= \Idno\Core\Idno::site()->language()->_('Items per page') ?></label>
                <input type="text" id="items_per_page" name="items_per_page" class="idno-input"
                       placeholder="10"
                       value="<?= htmlspecialchars(\Idno\Core\Idno::site()->config()->items_per_page) ?>">
            </div>

            <div class="idno-form-group">
                <div class="idno-checkbox-group">
                    <input type="checkbox" id="single_user" name="single_user" value="true"
                           <?php if (\Idno\Core\Idno::site()->config()->single_user) echo 'checked'; ?>>
                    <label for="single_user"><?= \Idno\Core\Idno::site()->language()->_('Single user site') ?></label>
                </div>
                <p class="idno-form-help"><?= \Idno\Core\Idno::site()->language()->_('Show profile info on homepage.') ?></p>
            </div>

            <div class="idno-form-group">
                <label class="idno-label"><?= \Idno\Core\Idno::site()->language()->_('Permalink Structure') ?></label>
                <?php foreach ([
                    '/:year/:slug' => '/:year/:slug (default)',
                    '/:year/:month/:slug' => '/:year/:month/:slug',
                    '/:year/:month/:day/:slug' => '/:year/:month/:day/:slug',
                ] as $value => $label) { ?>
                    <div class="idno-checkbox-group">
                        <input type="radio" name="permalink_structure" value="<?= $value ?>"
                            <?= \Idno\Core\Idno::site()->config()->getPermalinkStructure() == $value ? 'checked' : '' ?>>
                        <label><?= $label ?></label>
                    </div>
                <?php } ?>
            </div>

            <?= $this->draw('admin/home/settings/details') ?>
        </div>

        <div class="idno-admin-card">
            <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Registration and privacy') ?></h2>

            <div class="idno-form-group">
                <div class="idno-checkbox-group">
                    <input type="checkbox" name="open_registration" value="true"
                           <?php if (\Idno\Core\Idno::site()->config()->open_registration) echo 'checked'; ?>>
                    <label><?= \Idno\Core\Idno::site()->language()->_('Allow registration') ?></label>
                </div>
            </div>

            <?php if (\Idno\Core\Idno::site()->config()->walled_garden || \Idno\Core\Idno::site()->config()->canMakeSitePrivate()) { ?>
            <div class="idno-form-group">
                <div class="idno-checkbox-group">
                    <input type="checkbox" name="walled_garden" value="true"
                           <?php if (\Idno\Core\Idno::site()->config()->walled_garden) echo 'checked'; ?>>
                    <label><?= \Idno\Core\Idno::site()->language()->_('Make site private') ?></label>
                </div>
            </div>
            <?php } ?>

            <?php if (\Idno\Core\Idno::site()->config()->show_privacy || \Idno\Core\Idno::site()->config()->canMakeSitePrivate()) { ?>
            <div class="idno-form-group">
                <div class="idno-checkbox-group">
                    <input type="checkbox" name="show_privacy" value="true"
                           <?php if (\Idno\Core\Idno::site()->config()->show_privacy) echo 'checked'; ?>>
                    <label><?= \Idno\Core\Idno::site()->language()->_('Per-post privacy') ?></label>
                </div>
            </div>
            <?php } ?>

            <?= $this->draw('admin/home/settings/privacy') ?>
        </div>

        <div class="idno-admin-card">
            <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Technical Settings') ?></h2>

            <div class="idno-form-group">
                <label class="idno-label" for="hub"><?= \Idno\Core\Idno::site()->language()->_('PubSubHubbub hub') ?></label>
                <input type="url" id="hub" name="hub" class="idno-input"
                       value="<?= htmlspecialchars(\Idno\Core\Idno::site()->config()->hub) ?>">
            </div>

            <div class="idno-form-group">
                <div class="idno-checkbox-group">
                    <input type="checkbox" name="user_avatar_favicons" value="true"
                           <?php if (\Idno\Core\Idno::site()->config()->user_avatar_favicons) echo 'checked'; ?>>
                    <label><?= \Idno\Core\Idno::site()->language()->_('Avatar as favicon') ?></label>
                </div>
            </div>

            <div class="idno-form-group">
                <label class="idno-label" for="share_backup_url"><?= \Idno\Core\Idno::site()->language()->_('Backup share image') ?></label>
                <input type="url" id="share_backup_url" name="share_backup_url" class="idno-input"
                       placeholder="<?= \Idno\Core\Idno::site()->language()->_('Backup share image URL') ?>"
                       value="<?= htmlspecialchars(\Idno\Core\Idno::site()->config()->share_backup_url) ?>">
                <p class="idno-form-help"><?= \Idno\Core\Idno::site()->language()->_('Specify the URL of an image to use as your default social share image.') ?></p>
            </div>

            <div class="idno-form-group">
                <div class="idno-checkbox-group">
                    <input type="checkbox" name="indieweb_reference" value="true"
                           <?php if (\Idno\Core\Idno::site()->config()->indieweb_reference) echo 'checked'; ?>>
                    <label><?= \Idno\Core\Idno::site()->language()->_('Include permalinks when syndicating') ?></label>
                </div>
            </div>

            <?= $this->draw('admin/home/settings/technical') ?>
        </div>

        <?= $this->draw('admin/home/settings') ?>

        <div style="margin-top:var(--spacing-section)">
            <button type="submit" class="idno-btn idno-btn-primary"><?= \Idno\Core\Idno::site()->language()->_('Save updates') ?></button>
        </div>

        <?= \Idno\Core\Idno::site()->actions()->signForm('/admin/') ?>
        <?= $this->draw('admin/home/footer/settings') ?>

    </form>
