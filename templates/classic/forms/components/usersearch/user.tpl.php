<?php

$user = $vars['user'];

if ($user instanceof \Idno\Entities\User) {
    $handle = $user->getHandle();
    if (!empty($handle)) {
        if (strlen($handle) > 18) {
            $display_handle = substr($handle, 0, 16) . '...';
        } else {
            $display_handle = $handle;
        }
        /* @var \Idno\Entities\User $user */
        ?>

    <div class="row <?php echo strtolower(str_replace('\\', '-', get_class($user))); ?>">
        <div class="col-sm-4 col-xs-12">
        <p class="user-tbl">
            <img src="<?php echo $user->getIcon() ?>">
            <a href="<?php echo $user->getDisplayURL() ?>"><?php echo htmlentities($user->getTitle()) ?></a>
            (<a href="<?php echo $user->getDisplayURL() ?>"><?php echo $display_handle ?></a>)<br>
            <small><?php echo $user->email ?></small>
        </p>
        </div>
        <div class="col-sm-2 col-xs-6">
        <p class="user-tbl">
            <small><strong><?php echo \Idno\Core\Idno::site()->language()->_('Joined'); ?></strong><br>
            <time datetime="<?php echo date('r', $user->created) ?>"
                  class="dt-published"><?php echo date('r', $user->created) ?></time>
            </small>
        </p>
        </div>
        <div class="col-sm-2 col-xs-6">
        <p class="user-tbl">
            <small><strong><?php echo \Idno\Core\Idno::site()->language()->_('Last update posted'); ?></strong>
            <br>
            <?php
            $feed = \Idno\Common\Entity::getFromX(null, ['owner' => $user->getUUID()], array(), 1, 0);
            if (!empty($feed) && is_array($feed)) {
                ?>
                <time datetime="<?php echo date('r', $feed[0]->updated) ?>"
                      class="dt-published"><?php echo date('r', $feed[0]->updated) ?></time>
            <?php } else {
                ?>
                <?php echo \Idno\Core\Idno::site()->language()->_('Never'); ?>
            <?php }
            ?>
            </small>
        </p>
        </div>
        <div class="col-sm-2 col-xs-6">
        <p class="user-tbl">
            <small>
            <?php
            if ($user instanceof \Idno\Entities\RemoteUser) {
                ?>
                <?php echo \Idno\Core\Idno::site()->language()->_('Remote member'); ?>
                <?php
            } else {
                if ($user->isAdmin()) {
                    ?>
                <strong><?php echo \Idno\Core\Idno::site()->language()->_('Administrator'); ?></strong><br>
                    <?php
                    if ($user->getUUID() != \Idno\Core\Idno::site()->session()->currentUserUUID()) {
                        echo \Idno\Core\Idno::site()->actions()->createLink(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/users', \Idno\Core\Idno::site()->language()->_('Remove rights'), array('user' => $user->getUUID(), 'action' => 'remove_rights'), array('class' => ''));
                    } else {
                        echo \Idno\Core\Idno::site()->language()->_('Yes');
                    }
                } else {
                    ?>
                    <?php echo \Idno\Core\Idno::site()->language()->_('Standard member'); ?><br>
                    <?php echo \Idno\Core\Idno::site()->actions()->createLink(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/users', \Idno\Core\Idno::site()->language()->_('Make admin'), array('user' => $user->getUUID(), 'action' => 'add_rights'), array('class' => '')); ?>
                    <?php
                }
            }
            ?>
            </small>
        </p>
        </div>
        <div class="col-sm-2 col-xs-6">
        <p class="user-tbl">
            <small>
            <?php
            if ($user->getUUID() != \Idno\Core\Idno::site()->session()->currentUserUUID()) {
                if (\Idno\Core\Idno::site()->config()->emailIsBlocked($user->email)) {
                    echo \Idno\Core\Idno::site()->actions()->createLink(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/users', '<i class="fa fa-check-circle-o"></i> ' . \Idno\Core\Idno::site()->language()->_('Clear'), array('blocked_emails' => $user->email, 'action' => 'unblock_emails'), array('class' => '', 'confirm' => true, 'confirm-text' => \Idno\Core\Idno::site()->language()->_('Are you sure? The user will be able to log in and post again.'))) . '<br>';
                } else {
                    echo \Idno\Core\Idno::site()->actions()->createLink(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/users', '<i class="fa fa-ban"></i> ' . \Idno\Core\Idno::site()->language()->_('Block'), array('blocked_emails' => $user->email, 'action' => 'block_emails'), array('class' => '', 'confirm' => true, 'confirm-text' => \Idno\Core\Idno::site()->language()->_('Are you sure? The user will be logged out and will no longer be able to log in or post.'))) . '<br>';
                }
                echo \Idno\Core\Idno::site()->actions()->createLink(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/users', '<i class="fa fa-times"></i> ' . \Idno\Core\Idno::site()->language()->_('Delete'), array('user' => $user->getUUID(), 'action' => 'delete'), array('class' => '', 'confirm' => true, 'confirm-text' => \Idno\Core\Idno::site()->language()->_('Are you sure? This will delete this user and all their content.')));
            } else {
                echo '&nbsp';
            }
            ?>
            </small>
        </p>
        </div>
    </div>

        <?php
    }
}
