<h1 class="idno-admin-page-title"><?= \Idno\Core\Idno::site()->language()->_('Standard.site Sync') ?></h1>
<p class="idno-admin-description">
    <?= \Idno\Core\Idno::site()->language()->_('Automatically share your writing with the wider web. When you connect your account, everything you publish here will also appear on apps like <a href="https://leaflet.pub">Leaflet</a>, <a href="https://frontpage.fyi">Frontpage</a>, and other readers that support <a href="https://standard.site">standard.site</a>.') ?>
</p>
<p class="idno-admin-description">
    <?= \Idno\Core\Idno::site()->language()->_('Your content stays on your site &mdash; syncing just makes it discoverable in more places.') ?>
</p>

<?php if (empty($vars['sync_enabled'])) { ?>

<div class="idno-admin-card">
    <p><?= \Idno\Core\Idno::site()->language()->_('Syncing is not currently available. A site administrator needs to enable it before you can connect.') ?></p>
</div>

<?php } elseif (!empty($vars['connected'])) { ?>

<div class="idno-admin-card">
    <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('You\'re connected!') ?></h2>
    <p><?= \Idno\Core\Idno::site()->language()->_('New posts you publish will automatically be synced. You don\'t need to do anything else.') ?></p>

    <details style="margin-top:1rem;">
        <summary style="cursor:pointer;font-size:0.875rem;color:var(--color-muted,#6b7280);"><?= \Idno\Core\Idno::site()->language()->_('Show technical details') ?></summary>
        <dl style="margin-top:0.5rem;font-size:0.875rem;">
            <div style="display:flex;gap:0.5rem;margin-bottom:0.25rem;">
                <dt style="font-weight:600;"><?= \Idno\Core\Idno::site()->language()->_('Your identity') ?>:</dt>
                <dd><code><?= htmlspecialchars($vars['did']) ?></code></dd>
            </div>
            <div style="display:flex;gap:0.5rem;">
                <dt style="font-weight:600;"><?= \Idno\Core\Idno::site()->language()->_('Server') ?>:</dt>
                <dd><code><?= htmlspecialchars($vars['pds']) ?></code></dd>
            </div>
        </dl>
    </details>

    <form action="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>account/settings/standardsitesync/disconnect/" method="post" style="margin-top:1.5rem;">
        <?= \Idno\Core\Idno::site()->actions()->signForm('/account/settings/standardsitesync/disconnect/') ?>
        <button type="submit" class="idno-btn" style="color:var(--color-danger,#dc2626);border-color:var(--color-danger,#dc2626);"
                onclick="return confirm('<?= \Idno\Core\Idno::site()->language()->_('Are you sure you want to disconnect? Your posts will stop syncing.') ?>');">
            <?= \Idno\Core\Idno::site()->language()->_('Disconnect') ?>
        </button>
    </form>
</div>

<div class="idno-admin-card">
    <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Sync older posts') ?></h2>
    <p><?= \Idno\Core\Idno::site()->language()->_('Already have posts on this site? You can sync them all at once. Posts that have already been synced will be skipped, so it\'s safe to run this more than once.') ?></p>

    <form action="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>account/settings/standardsitesync/backfill/" method="post" style="margin-top:1rem;">
        <?= \Idno\Core\Idno::site()->actions()->signForm('/account/settings/standardsitesync/backfill/') ?>
        <button type="submit" class="idno-btn idno-btn-primary"
                onclick="return confirm('<?= \Idno\Core\Idno::site()->language()->_('This will sync all your existing posts. Continue?') ?>');">
            <?= \Idno\Core\Idno::site()->language()->_('Sync all my posts') ?>
        </button>
    </form>
</div>

<?php } else { ?>

<div class="idno-admin-card">
    <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Get started') ?></h2>
    <p><?= \Idno\Core\Idno::site()->language()->_('To start syncing, enter the handle for your AT Protocol account (this is usually your Bluesky handle, like <strong>yourname.bsky.social</strong>). You\'ll be taken to your server to approve the connection, then brought back here.') ?></p>

    <form action="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>account/settings/standardsitesync/" method="post" style="margin-top:1rem;">
        <?= \Idno\Core\Idno::site()->actions()->signForm('/account/settings/standardsitesync/') ?>
        <input type="hidden" name="action" value="connect">

        <div class="idno-form-group">
            <label class="idno-label" for="atproto-handle"><?= \Idno\Core\Idno::site()->language()->_('Your handle') ?></label>
            <div style="display:flex;gap:0.5rem;align-items:start;">
                <input type="text" id="atproto-handle" name="handle" placeholder="yourname.bsky.social" class="idno-input" style="max-width:20rem;" required>
                <button type="submit" class="idno-btn idno-btn-primary"><?= \Idno\Core\Idno::site()->language()->_('Connect') ?></button>
            </div>
            <p class="idno-form-help"><?= \Idno\Core\Idno::site()->language()->_('Don\'t have one? You can create a free account at <a href="https://bsky.app">bsky.app</a> or any AT Protocol provider.') ?></p>
        </div>
    </form>
</div>

<?php }
