<h1 class="idno-admin-page-title"><?= \Idno\Core\Idno::site()->language()->_('Users') ?></h1>
    <p class="idno-admin-description">
        <?= \Idno\Core\Idno::site()->language()->_('View the users registered for your site, and invite new users to join.') ?>
    </p>

    <div class="idno-admin-card">
        <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Manage site users') ?></h2>
        <p>
            <?= \Idno\Core\Idno::site()->language()->_('Your site has') ?> <strong><?= $vars['count'] ?></strong>
            <?php if ($vars['count'] != 1) {
                echo \Idno\Core\Idno::site()->language()->_('users');
            } else {
                echo \Idno\Core\Idno::site()->language()->_('user');
            } ?>.
        </p>

        <?= $this->__([])->draw('forms/usersearch') ?>
    </div>

    <?php if (\Idno\Core\Idno::site()->config()->canAddUsers()) { ?>
    <div class="idno-admin-card" id="invite">
        <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Invite people') ?></h2>
        <?= $this->__(['mail_configured' => $vars['mail_configured'] ?? false])->draw('admin/users/invite') ?>
    </div>
    <?php } ?>

    <?php if (!empty($vars['invitations'])) { ?>
    <div class="idno-admin-card" id="invitations">
        <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Invitations waiting to be accepted') ?></h2>
        <?= $this->__(['invitations' => $vars['invitations']])->draw('admin/users/invitations') ?>
    </div>
    <?php } ?>

    <?php if (!empty($vars['reserved_handles']['items'])) { ?>
    <div class="idno-admin-card" id="reserved-usernames">
        <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Usernames of deleted accounts') ?></h2>
        <?= $this->__(['reserved_handles' => $vars['reserved_handles']])->draw('admin/users/reserved') ?>
    </div>
    <?php } ?>

    <div class="idno-admin-card">
        <form action="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>admin/users" method="post">
            <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Block email addresses') ?></h2>
            <p>
                <?= \Idno\Core\Idno::site()->language()->_('By blocking email addresses, you prevent people using those email addresses from registering on your site. Enter the email addresses you want to block below.') ?>
            </p>
            <div class="idno-form-group">
                <textarea name="blocked_emails" class="idno-input" placeholder="user@email.com"></textarea>
            </div>
            <div class="idno-form-group">
                <button type="submit" class="idno-btn idno-btn-primary"><?= \Idno\Core\Idno::site()->language()->_('Block email addresses') ?></button>
                <input type="hidden" name="action" value="block_emails">
                <?= \Idno\Core\Idno::site()->actions()->signForm('/admin/users') ?>
            </div>
        </form>
    </div>

    <?php
    if ($blocked_emails = \Idno\Core\Idno::site()->config()->getBlockedEmails()) {
    ?>
    <div class="idno-admin-card">
        <h2 class="idno-admin-card-title"><?= \Idno\Core\Idno::site()->language()->_('Blocked email addresses') ?></h2>
        <?php foreach ($blocked_emails as $email) { ?>
            <div class="idno-admin-list-item">
                <div><?= htmlspecialchars($email) ?></div>
                <div>
                    <small>
                        <?= \Idno\Core\Idno::site()->actions()->createLink(
                            \Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/users',
                            \Idno\Core\Idno::site()->language()->_('Remove block'),
                            array('blocked_emails' => $email, 'action' => 'unblock_emails'),
                            array('class' => 'idno-btn idno-btn-small', 'confirm' => true, 'confirm-text' => 'Are you sure? The user will be able to log in and post again.')
                        ) ?>
                    </small>
                </div>
            </div>
        <?php } ?>
    </div>
    <?php } ?>

    <?= $this->draw('admin/users/extensions') ?>
