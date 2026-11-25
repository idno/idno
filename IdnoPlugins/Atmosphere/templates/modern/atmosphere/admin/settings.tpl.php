<h1 class="idno-admin-page-title"><?= \Idno\Core\Idno::site()->language()->_('Atmosphere') ?></h1>
<p class="idno-admin-description">
    <?= \Idno\Core\Idno::site()->language()->_('Lets members save what they publish here to their own account on the Atmosphere, the network of apps built on the AT Protocol (Bluesky is the best known). Articles become <a href="https://standard.site">standard.site</a> documents, which apps like Leaflet and Frontpage read; status updates, videos and audio become records of their own.') ?>
</p>

<div class="idno-admin-card">
    <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Site-wide setting') ?></h2>
    <p><?= \Idno\Core\Idno::site()->language()->_('Turn syncing to the Atmosphere on or off for this site. When it is on, members can connect their own AT Protocol accounts from their account settings.') ?></p>

    <form action="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>admin/atmosphere/" method="post">
        <?= \Idno\Core\Idno::site()->actions()->signForm('/admin/atmosphere/') ?>

        <div class="idno-form-group" style="margin-top:1rem;">
            <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                <input type="checkbox" name="sync_enabled" value="1" <?php if (!empty($vars['sync_enabled'])) echo 'checked'; ?>>
                <?= \Idno\Core\Idno::site()->language()->_('Sync members\' posts to the Atmosphere') ?>
            </label>
        </div>

        <div class="idno-form-actions">
            <button type="submit" class="idno-btn idno-btn-primary"><?= \Idno\Core\Idno::site()->language()->_('Save') ?></button>
        </div>
    </form>
</div>
