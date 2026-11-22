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
        $isSelf = ($user->getUUID() == \Idno\Core\Idno::site()->session()->currentUserUUID());
        ?>

    <div class="idno-user-row <?= strtolower(str_replace('\\', '-', get_class($user))) ?>">
        <div class="idno-user-info">
            <img src="<?= $user->getIcon() ?>" alt="" class="idno-user-avatar">
            <div class="idno-user-details">
                <a href="<?= $user->getDisplayURL() ?>" class="idno-user-name"><?= htmlentities($user->getTitle()) ?></a>
                <span class="idno-user-handle"><?= $display_handle ?></span>
                <span class="idno-user-email"><?= $user->email ?></span>
            </div>
        </div>
        <div class="idno-user-meta">
            <div class="idno-user-meta-item">
                <strong><?= \Idno\Core\Idno::site()->language()->_('Joined') ?></strong>
                <time datetime="<?= date('c', $user->created) ?>"><?= date('M j, Y', $user->created) ?></time>
            </div>
            <div class="idno-user-meta-item">
                <strong><?= \Idno\Core\Idno::site()->language()->_('Last post') ?></strong>
                <?php
                $feed = \Idno\Common\Entity::getFromX(null, ['owner' => $user->getUUID()], array(), 1, 0);
                if (!empty($feed) && is_array($feed)) {
                    ?>
                    <time datetime="<?= date('c', $feed[0]->updated) ?>"><?= date('M j, Y', $feed[0]->updated) ?></time>
                <?php } else { ?>
                    <span><?= \Idno\Core\Idno::site()->language()->_('Never') ?></span>
                <?php } ?>
            </div>
            <div class="idno-user-meta-item">
                <?php
                if ($user instanceof \Idno\Entities\RemoteUser) {
                    echo '<span>' . \Idno\Core\Idno::site()->language()->_('Remote member') . '</span>';
                } else if ($user->isAdmin()) {
                    echo '<strong>' . \Idno\Core\Idno::site()->language()->_('Administrator') . '</strong>';
                } else {
                    echo '<span>' . \Idno\Core\Idno::site()->language()->_('Member') . '</span>';
                }
                ?>
            </div>
        </div>
        <div class="idno-user-actions">
            <?php if (!$isSelf) {
                // Admin toggle
                if (!($user instanceof \Idno\Entities\RemoteUser)) {
                    if ($user->isAdmin()) {
                        echo \Idno\Core\Idno::site()->actions()->createLink(
                            \Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/users',
                            \Idno\Core\Idno::site()->language()->_('Remove admin'),
                            array('user' => $user->getUUID(), 'action' => 'remove_rights'),
                            array('class' => 'idno-user-action-link')
                        );
                    } else {
                        echo \Idno\Core\Idno::site()->actions()->createLink(
                            \Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/users',
                            \Idno\Core\Idno::site()->language()->_('Make admin'),
                            array('user' => $user->getUUID(), 'action' => 'add_rights'),
                            array('class' => 'idno-user-action-link')
                        );
                    }
                }
                // Block/unblock
                if (\Idno\Core\Idno::site()->config()->emailIsBlocked($user->email)) {
                    echo \Idno\Core\Idno::site()->actions()->createLink(
                        \Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/users',
                        \Idno\Core\Idno::site()->language()->_('Unblock'),
                        array('blocked_emails' => $user->email, 'action' => 'unblock_emails'),
                        array('class' => 'idno-user-action-link', 'confirm' => true, 'confirm-text' => \Idno\Core\Idno::site()->language()->_('Are you sure? The user will be able to log in and post again.'))
                    );
                } else {
                    echo \Idno\Core\Idno::site()->actions()->createLink(
                        \Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/users',
                        \Idno\Core\Idno::site()->language()->_('Block'),
                        array('blocked_emails' => $user->email, 'action' => 'block_emails'),
                        array('class' => 'idno-user-action-link idno-user-action-danger', 'confirm' => true, 'confirm-text' => \Idno\Core\Idno::site()->language()->_('Are you sure? The user will be logged out and will no longer be able to log in or post.'))
                    );
                }
                // Delete
                echo \Idno\Core\Idno::site()->actions()->createLink(
                    \Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/users',
                    \Idno\Core\Idno::site()->language()->_('Delete'),
                    array('user' => $user->getUUID(), 'action' => 'delete'),
                    array('class' => 'idno-user-action-link idno-user-action-danger', 'confirm' => true, 'confirm-text' => \Idno\Core\Idno::site()->language()->_('Are you sure? This will delete this user and all their content.'))
                );
            } ?>
        </div>
    </div>

        <?php
    }
}
