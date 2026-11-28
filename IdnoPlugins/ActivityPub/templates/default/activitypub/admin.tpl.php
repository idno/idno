<div class="row">
    <div class="col-md-10 col-md-offset-1">
        <?php echo $this->draw('admin/menu')?>

        <h1><?php echo \Idno\Core\Idno::site()->language()->_('ActivityPub federation'); ?></h1>

        <?php if (!\IdnoPlugins\ActivityPub\Main::federates()) { ?>
        <div class="alert alert-info">
            <strong><?php echo \Idno\Core\Idno::site()->language()->_('This site is private, so federation is off'); ?></strong>
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_('Nothing posted here is sent to other servers, members cannot be found or followed from them, and no new follower is accepted. Followers from before the site was made private are kept, and are sent new posts again if the site is made public.'); ?>
            </p>
        </div>
        <?php } ?>

        <?php if (empty($vars['delivers_in_background']) && \IdnoPlugins\ActivityPub\Main::federates()) { ?>
        <div class="alert alert-warning" id="activitypub-in-request-delivery">
            <strong><?php echo \Idno\Core\Idno::site()->language()->_('Posts are delivered while members wait'); ?></strong>
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_('This site federates without the asynchronous event queue: follows are accepted, and when a member publishes, changes or deletes a post it is sent to every follower’s server before the member’s page comes back. With many followers, or a slow server among them, publishing is slow and can time out.'); ?>
                <?php echo \Idno\Core\Idno::site()->language()->_('To deliver in the background, set <code>event_queue = \'AsynchronousQueue\'</code> in <code>config.ini</code> and run the queue worker: see the <a href="https://github.com/idno/idno#setting-up-the-async-pipeline">setup instructions</a> in the README.'); ?>
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
                    <th><?php echo \Idno\Core\Idno::site()->language()->_('ActivityPub followers'); ?></th>
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
            <strong><?php echo \Idno\Core\Idno::site()->language()->_('Shared inbox'); ?>:</strong>
            <code><?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>inbox</code>
        </p>
        <p>
            <strong><?php echo \Idno\Core\Idno::site()->language()->_('NodeInfo'); ?>:</strong>
            <code><?php echo \Idno\Core\Idno::site()->config()->getDisplayURL()?>.well-known/nodeinfo</code>
        </p>

    </div>
</div>
