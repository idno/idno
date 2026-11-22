<div class="row">
    <div class="col-md-10 col-md-offset-1">
        <?php echo $this->draw('admin/menu')?>

        <h1><?php echo \Idno\Core\Idno::site()->language()->_('ActivityPub Federation'); ?></h1>

        <?php if (empty($vars['queue_ok'])) { ?>
        <div class="alert alert-danger">
            <strong><?php echo \Idno\Core\Idno::site()->language()->_('Asynchronous queue required'); ?></strong>
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_('ActivityPub needs the asynchronous event queue to deliver posts and accept follows from other platforms.'); ?>
                <?php echo \Idno\Core\Idno::site()->language()->_('Please see the <a href="https://github.com/idno/idno#setting-up-the-async-pipeline">setup instructions</a> in the README to enable it.'); ?>
            </p>
        </div>
        <?php } ?>

        <p class="explanation">
            <?php echo \Idno\Core\Idno::site()->language()->_('ActivityPub federation allows users on this site to be followed from Mastodon and other compatible platforms. When users publish content, it is automatically delivered to their followers on the fediverse.'); ?>
        </p>

        <div class="well">
            <h3><?php echo \Idno\Core\Idno::site()->language()->_('Status'); ?></h3>
            <p>
                <strong><?php echo \Idno\Core\Idno::site()->language()->_('Total users'); ?>:</strong> <?php echo $vars['total_users']?>
                &nbsp;&nbsp;&nbsp;
                <strong><?php echo \Idno\Core\Idno::site()->language()->_('Total ActivityPub followers'); ?>:</strong> <?php echo $vars['total_followers']?>
            </p>
        </div>

        <?php if (!empty($vars['user_stats'])) { ?>
        <h3><?php echo \Idno\Core\Idno::site()->language()->_('Users'); ?></h3>
        <table class="table table-striped">
            <thead>
                <tr>
                    <th><?php echo \Idno\Core\Idno::site()->language()->_('User'); ?></th>
                    <th><?php echo \Idno\Core\Idno::site()->language()->_('Handle'); ?></th>
                    <th><?php echo \Idno\Core\Idno::site()->language()->_('AP Followers'); ?></th>
                    <th><?php echo \Idno\Core\Idno::site()->language()->_('Keys'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($vars['user_stats'] as $stat) { ?>
                <tr>
                    <td><?php echo htmlspecialchars($stat['name'])?></td>
                    <td><?php echo htmlspecialchars($stat['handle'])?></td>
                    <td><?php echo $stat['followers']?></td>
                    <td><?php echo $stat['has_keys'] ? '<span class="label label-success">' . \Idno\Core\Idno::site()->language()->_('Generated') . '</span>' : '<span class="label label-warning">' . \Idno\Core\Idno::site()->language()->_('Pending') . '</span>' ?></td>
                </tr>
                <?php } ?>
            </tbody>
        </table>
        <?php } ?>

        <h3><?php echo \Idno\Core\Idno::site()->language()->_('Configuration'); ?></h3>
        <p>
            <?php echo \Idno\Core\Idno::site()->language()->_('ActivityPub is enabled. To disable it, go to the Plugins page and deactivate the ActivityPub plugin.'); ?>
        </p>
        <p>
            <strong><?php echo \Idno\Core\Idno::site()->language()->_('WebFinger'); ?>:</strong>
            <code><?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>.well-known/webfinger</code>
        </p>
        <p>
            <strong><?php echo \Idno\Core\Idno::site()->language()->_('Shared Inbox'); ?>:</strong>
            <code><?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>inbox</code>
        </p>
        <p>
            <strong><?php echo \Idno\Core\Idno::site()->language()->_('NodeInfo'); ?>:</strong>
            <code><?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>.well-known/nodeinfo</code>
        </p>

    </div>
</div>
