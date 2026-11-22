<h1 class="idno-admin-page-title"><?= \Idno\Core\Idno::site()->language()->_('ActivityPub Federation') ?></h1>
<p class="idno-admin-description">
    <?= \Idno\Core\Idno::site()->language()->_('ActivityPub federation allows users on this site to be followed from Mastodon and other compatible platforms. When users publish content, it is automatically delivered to their followers on the fediverse.') ?>
</p>

<?php if (empty($vars['queue_ok'])) { ?>
<div class="idno-admin-card" style="border-left:4px solid var(--color-warning,#eab308);background:var(--color-warning-bg,#fefce8)">
    <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Asynchronous queue required') ?></h2>
    <p>
        <?= \Idno\Core\Idno::site()->language()->_('ActivityPub needs the asynchronous event queue to deliver posts and accept follows from other platforms.') ?>
        <?= \Idno\Core\Idno::site()->language()->_('Please see the <a href="https://github.com/idno/idno#setting-up-the-async-pipeline">setup instructions</a> in the README to enable it.') ?>
    </p>
</div>
<?php } ?>

<div class="idno-admin-card">
    <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Status') ?></h2>
    <div style="display:flex;gap:2rem;margin-top:0.5rem;">
        <div class="idno-admin-stat">
            <div class="idno-admin-stat-value"><?= $vars['total_users'] ?></div>
            <div class="idno-admin-stat-label"><?= \Idno\Core\Idno::site()->language()->_('Total Users') ?></div>
        </div>
        <div class="idno-admin-stat">
            <div class="idno-admin-stat-value"><?= $vars['total_followers'] ?></div>
            <div class="idno-admin-stat-label"><?= \Idno\Core\Idno::site()->language()->_('AP Followers') ?></div>
        </div>
    </div>
</div>

<?php if (!empty($vars['user_stats'])) { ?>
<div class="idno-admin-card">
    <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Users') ?></h2>
    <table style="width:100%;border-collapse:collapse;">
        <thead>
            <tr style="border-bottom:2px solid var(--color-border,#e5e7eb);">
                <th style="text-align:left;padding:0.5rem 0.5rem;font-weight:600;"><?= \Idno\Core\Idno::site()->language()->_('User') ?></th>
                <th style="text-align:left;padding:0.5rem 0.5rem;font-weight:600;"><?= \Idno\Core\Idno::site()->language()->_('Handle') ?></th>
                <th style="text-align:left;padding:0.5rem 0.5rem;font-weight:600;"><?= \Idno\Core\Idno::site()->language()->_('AP Followers') ?></th>
                <th style="text-align:left;padding:0.5rem 0.5rem;font-weight:600;"><?= \Idno\Core\Idno::site()->language()->_('Keys') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($vars['user_stats'] as $stat) { ?>
            <tr style="border-bottom:1px solid var(--color-border,#e5e7eb);">
                <td style="padding:0.5rem 0.5rem;"><?= htmlspecialchars($stat['name']) ?></td>
                <td style="padding:0.5rem 0.5rem;"><code><?= htmlspecialchars($stat['handle']) ?></code></td>
                <td style="padding:0.5rem 0.5rem;"><?= $stat['followers'] ?></td>
                <td style="padding:0.5rem 0.5rem;">
                    <?php if ($stat['has_keys']) { ?>
                        <span style="color:var(--color-success,#16a34a);">✓ <?= \Idno\Core\Idno::site()->language()->_('Generated') ?></span>
                    <?php } else { ?>
                        <span style="color:var(--color-warning,#eab308);">⏳ <?= \Idno\Core\Idno::site()->language()->_('Pending') ?></span>
                    <?php } ?>
                </td>
            </tr>
            <?php } ?>
        </tbody>
    </table>
</div>
<?php } ?>

<div class="idno-admin-card">
    <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Configuration') ?></h2>
    <p><?= \Idno\Core\Idno::site()->language()->_('ActivityPub is enabled. To disable it, go to the Plugins page and deactivate the ActivityPub plugin.') ?></p>
    <dl style="margin-top:0.75rem;">
        <div style="display:flex;gap:0.5rem;margin-bottom:0.4rem;">
            <dt style="font-weight:600;min-width:8rem;"><?= \Idno\Core\Idno::site()->language()->_('WebFinger') ?>:</dt>
            <dd><code><?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>.well-known/webfinger</code></dd>
        </div>
        <div style="display:flex;gap:0.5rem;margin-bottom:0.4rem;">
            <dt style="font-weight:600;min-width:8rem;"><?= \Idno\Core\Idno::site()->language()->_('Shared Inbox') ?>:</dt>
            <dd><code><?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>inbox</code></dd>
        </div>
        <div style="display:flex;gap:0.5rem;margin-bottom:0.4rem;">
            <dt style="font-weight:600;min-width:8rem;"><?= \Idno\Core\Idno::site()->language()->_('NodeInfo') ?>:</dt>
            <dd><code><?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>.well-known/nodeinfo</code></dd>
        </div>
    </dl>
</div>
