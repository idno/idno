<h1 class="idno-admin-page-title"><?= \Idno\Core\Idno::site()->language()->_('Import') ?></h1>
    <p class="idno-admin-description">
        <?= \Idno\Core\Idno::site()->language()->_('Import your content from other sites into Idno. All imported content will be treated as a post, with a title and body content. Posts that are private or drafts at their source stay private or drafts here, and nothing you import is announced to your followers.') ?>
    </p>
    <p class="idno-admin-description">
        <?= \Idno\Core\Idno::site()->language()->_('Importing the same file twice creates the posts twice.') ?>
    </p>

    <div class="idno-admin-card">
        <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('WordPress') ?></h2>
        <form action="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>admin/import/" method="post" enctype="multipart/form-data">
            <p>
                <?= \Idno\Core\Idno::site()->language()->_('Upload a WordPress XML file and turn it into Idno posts.') ?> <?= \Idno\Core\Idno::site()->language()->_('An RSS file exported from another Idno site can be imported here too.') ?>
            </p>
            <div class="idno-form-group">
                <label class="idno-label"><?= \Idno\Core\Idno::site()->language()->_('Select WordPress export file') ?></label>
                <input type="file" name="import" accept=".xml,.atom,.rss" class="idno-input">
            </div>
            <div class="idno-form-group">
                <?= \Idno\Core\Idno::site()->actions()->signForm('/admin/import') ?>
                <input type="hidden" name="import_type" value="WordPress">
                <button type="submit" class="idno-btn idno-btn-primary"><?= \Idno\Core\Idno::site()->language()->_('Import your data') ?></button>
            </div>
        </form>
    </div>

    <div class="idno-admin-card">
        <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Blogger') ?></h2>
        <form action="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>admin/import/" method="post" enctype="multipart/form-data">
            <p>
                <?= \Idno\Core\Idno::site()->language()->_('Upload a Blogger XML file and turn it into Idno posts.') ?>
            </p>
            <div class="idno-form-group">
                <label class="idno-label"><?= \Idno\Core\Idno::site()->language()->_('Select Blogger export file') ?></label>
                <input type="file" name="import" accept=".xml,.atom" class="idno-input">
            </div>
            <div class="idno-form-group">
                <?= \Idno\Core\Idno::site()->actions()->signForm('/admin/import') ?>
                <input type="hidden" name="import_type" value="Blogger">
                <button type="submit" class="idno-btn idno-btn-primary"><?= \Idno\Core\Idno::site()->language()->_('Import your data') ?></button>
            </div>
        </form>
    </div>
