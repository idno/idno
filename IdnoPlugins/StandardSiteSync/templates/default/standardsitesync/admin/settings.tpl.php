<h1 class="idno-admin-page-title"><?= \Idno\Core\Idno::site()->language()->_('Standard.site Sync') ?></h1>
<p class="idno-admin-description">
    <?= \Idno\Core\Idno::site()->language()->_('Sync your writing to <a href="https://standard.site">standard.site</a> records on an AT Protocol Personal Data Server (PDS). This makes your content discoverable across the AT Protocol network via apps like Leaflet, Frontpage, and others that support the standard.site lexicons.') ?>
</p>

<div class="idno-admin-card">
    <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Site-Wide Setting') ?></h2>
    <p><?= \Idno\Core\Idno::site()->language()->_('Enable or disable standard.site sync for this site. When enabled, users can connect their own AT Protocol PDS accounts from their account settings.') ?></p>

    <form action="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>admin/standardsitesync/" method="post">
        <?= \Idno\Core\Idno::site()->actions()->signForm('/admin/standardsitesync/') ?>

        <div class="idno-form-group" style="margin-top:1rem;">
            <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                <input type="checkbox" name="sync_enabled" value="1" <?php if (!empty($vars['sync_enabled'])) echo 'checked'; ?>>
                <?= \Idno\Core\Idno::site()->language()->_('Enable standard.site sync') ?>
            </label>
        </div>

        <div class="idno-form-actions">
            <button type="submit" class="idno-btn idno-btn-primary"><?= \Idno\Core\Idno::site()->language()->_('Save') ?></button>
        </div>
    </form>
</div>
