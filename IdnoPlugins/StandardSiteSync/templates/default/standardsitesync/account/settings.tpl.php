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
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_('Status updates, videos and audio are saved to your account too, as records of their own. They don\'t appear in Bluesky unless you ask for that below. When you edit or delete a post here, its record is changed or deleted too. Only posts that are public and published are shared: drafts and private posts never are.'); ?>
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
            <?php if (($vars['failure']['kind'] ?? null) === 'expired') { ?>
            <h3 class="text-danger"><?php echo \Idno\Core\Idno::site()->language()->_('Your connection has expired'); ?></h3>
            <p role="alert"><?php echo \Idno\Core\Idno::site()->language()->_('This site can no longer save your posts to your account, so nothing you publish is being synced. Disconnect below, then connect again. Afterwards, &ldquo;Sync all my posts&rdquo; will send what was missed.'); ?></p>
            <?php } else { ?>
            <h3><?php echo \Idno\Core\Idno::site()->language()->_('You\'re connected!'); ?></h3>
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_('New posts you publish will automatically be synced. You don\'t need to do anything else.'); ?>
            </p>
                <?php if (!empty($vars['failure'])) { ?>
                <p role="alert" class="text-danger"><?php echo \Idno\Core\Idno::site()->language()->_('The last attempt to save a post to your account failed, on %s. It will be tried again the next time that post is saved. If this keeps happening, disconnect and connect again.', [htmlspecialchars(date('j F Y, H:i', $vars['failure']['at']))]); ?></p>
                <?php } ?>
            <?php } ?>
            <?php if (!empty($vars['failure']['detail'])) { ?>
            <p class="help-block"><?php echo \Idno\Core\Idno::site()->language()->_('What the server answered: %s', [htmlspecialchars($vars['failure']['detail'])]); ?></p>
            <?php } ?>

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

        <!-- Bluesky -->
        <div class="well">
            <h3><?php echo \Idno\Core\Idno::site()->language()->_('Bluesky'); ?></h3>
            <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>account/settings/standardsitesync/" method="post">
                <?php echo \Idno\Core\Idno::site()->actions()->signForm('/account/settings/standardsitesync/') ?>
                <input type="hidden" name="action" value="options">
                <div class="checkbox">
                    <label for="atproto-bluesky">
                        <input type="checkbox" id="atproto-bluesky" name="bluesky" value="1"<?php echo !empty($vars['bluesky']) ? ' checked' : '' ?>>
                        <?php echo \Idno\Core\Idno::site()->language()->_('Also post my status updates, videos and audio to Bluesky'); ?>
                    </label>
                </div>
                <p class="help-block"><?php echo \Idno\Core\Idno::site()->language()->_('Your followers on Bluesky will see each one as a post, with a link back here where one is needed. If you turn this off, nothing more is posted there; posts already there stay until you delete them here or there.'); ?></p>
                <button type="submit" class="btn btn-primary"><?php echo \Idno\Core\Idno::site()->language()->_('Save'); ?></button>
            </form>
        </div>

        <!-- Backfill -->
        <div class="well">
            <h3><?php echo \Idno\Core\Idno::site()->language()->_('Sync older posts'); ?></h3>
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_('Already have posts on this site? You can sync them all at once. Posts that have already been synced will be skipped, so it\'s safe to run this more than once. If you have asked for your posts to go to Bluesky, older status updates, videos and audio will appear there under their original dates.'); ?>
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
