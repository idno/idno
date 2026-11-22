<div class="row">
    <div class="col-md-10 col-md-offset-1">
        <?php echo $this->draw('account/menu')?>

        <h1><?php echo \Idno\Core\Idno::site()->language()->_('Standard.site Sync'); ?></h1>

        <div class="explanation">
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_('Automatically share your writing with the wider web. When you connect your account, everything you publish here will also appear on apps like <a href="https://leaflet.pub">Leaflet</a>, <a href="https://frontpage.fyi">Frontpage</a>, and other readers that support <a href="https://standard.site">standard.site</a>.'); ?>
            </p>
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_('Your content stays on your site &mdash; syncing just makes it discoverable in more places.'); ?>
            </p>
        </div>

        <?php if (empty($vars['sync_enabled'])) { ?>

        <div class="well">
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_('Syncing is not currently available. A site administrator needs to enable it before you can connect.'); ?>
            </p>
        </div>

        <?php } elseif (!empty($vars['connected'])) { ?>

        <!-- Connected state -->
        <div class="well">
            <h3><?php echo \Idno\Core\Idno::site()->language()->_('You\'re connected!'); ?></h3>
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_('New posts you publish will automatically be synced. You don\'t need to do anything else.'); ?>
            </p>

            <p style="margin-top: 1em;">
                <a href="#" onclick="var el = document.getElementById('standardsitesync-details'); el.style.display = el.style.display === 'none' ? 'block' : 'none'; this.textContent = el.style.display === 'none' ? '<?php echo \Idno\Core\Idno::site()->language()->_('Show technical details'); ?>' : '<?php echo \Idno\Core\Idno::site()->language()->_('Hide technical details'); ?>'; return false;"><?php echo \Idno\Core\Idno::site()->language()->_('Show technical details'); ?></a>
            </p>
            <dl id="standardsitesync-details" class="dl-horizontal" style="display: none;">
                <dt><?php echo \Idno\Core\Idno::site()->language()->_('Your identity'); ?></dt>
                <dd><code><?php echo htmlspecialchars($vars['did'])?></code></dd>
                <dt><?php echo \Idno\Core\Idno::site()->language()->_('Server'); ?></dt>
                <dd><code><?php echo htmlspecialchars($vars['pds'])?></code></dd>
            </dl>

            <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>account/settings/standardsitesync/disconnect/" method="post" style="margin-top: 1.5em;">
                <?php echo \Idno\Core\Idno::site()->actions()->signForm('/account/settings/standardsitesync/disconnect/') ?>
                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('<?php echo \Idno\Core\Idno::site()->language()->_('Are you sure you want to disconnect? Your posts will stop syncing.'); ?>');">
                    <?php echo \Idno\Core\Idno::site()->language()->_('Disconnect'); ?>
                </button>
            </form>
        </div>

        <!-- Backfill -->
        <div class="well">
            <h3><?php echo \Idno\Core\Idno::site()->language()->_('Sync older posts'); ?></h3>
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_('Already have posts on this site? You can sync them all at once. Posts that have already been synced will be skipped, so it\'s safe to run this more than once.'); ?>
            </p>

            <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>account/settings/standardsitesync/backfill/" method="post" style="margin-top: 1em;">
                <?php echo \Idno\Core\Idno::site()->actions()->signForm('/account/settings/standardsitesync/backfill/') ?>
                <button type="submit" class="btn btn-primary" onclick="return confirm('<?php echo \Idno\Core\Idno::site()->language()->_('This will sync all your existing posts. Continue?'); ?>');">
                    <?php echo \Idno\Core\Idno::site()->language()->_('Sync all my posts'); ?>
                </button>
            </form>
        </div>

        <?php } else { ?>

        <!-- Not connected state -->
        <div class="well">
            <h3><?php echo \Idno\Core\Idno::site()->language()->_('Get started'); ?></h3>
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_('To start syncing, enter the handle for your AT Protocol account (this is usually your Bluesky handle, like <strong>yourname.bsky.social</strong>). You\'ll be taken to your server to approve the connection, then brought back here.'); ?>
            </p>

            <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>account/settings/standardsitesync/" method="post">
                <?php echo \Idno\Core\Idno::site()->actions()->signForm('/account/settings/standardsitesync/') ?>
                <input type="hidden" name="action" value="connect">
                <div class="form-group">
                    <label for="atproto-handle"><?php echo \Idno\Core\Idno::site()->language()->_('Your handle'); ?></label>
                    <div class="row">
                        <div class="col-md-6">
                            <input type="text" id="atproto-handle" name="handle" placeholder="yourname.bsky.social" class="form-control" required>
                        </div>
                        <div class="col-md-4">
                            <button type="submit" class="btn btn-primary">
                                <?php echo \Idno\Core\Idno::site()->language()->_('Connect'); ?>
                            </button>
                        </div>
                    </div>
                    <p class="help-block">
                        <?php echo \Idno\Core\Idno::site()->language()->_('Don\'t have one? You can create a free account at <a href="https://bsky.app">bsky.app</a> or any AT Protocol provider.'); ?>
                    </p>
                </div>
            </form>
        </div>

        <?php } ?>

    </div>
</div>
