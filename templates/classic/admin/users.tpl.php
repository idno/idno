<div class="row">
    <div class="col-md-10 col-md-offset-1">
        <?php echo $this->draw('admin/menu') ?>
        <h1><?php echo \Idno\Core\Idno::site()->language()->_('Users'); ?></h1>


        <div class="explanation">
            <p>
                <?php echo \Idno\Core\Idno::site()->language()->_('View the users registered for your site, and invite new users to join.'); ?>
            </p>

        </div>
    </div>
</div>
<div class="row">
    <div class="col-md-10 col-md-offset-1">
        <h3><?php echo \Idno\Core\Idno::site()->language()->_('Manage site users'); ?></h3>

        <p>
            <?php echo \Idno\Core\Idno::site()->language()->_('Your site has'); ?> <strong><?php echo $vars['count'] ?></strong> <?php if ($vars['count'] != 1) {
                                                                                                                       echo \Idno\Core\Idno::site()->language()->_('users');
            } else {
                echo \Idno\Core\Idno::site()->language()->_('user');
            } ?>.
        </p>
    </div>
</div>
<div class="row">
    <div class="col-md-10 col-md-offset-1">
    
    <?php echo $this->__([])->draw('forms/usersearch'); ?>
    
    </div>
</div>
<?php

if (\Idno\Core\Idno::site()->config()->canAddUsers()) {

    ?>
<div class="row">
    <div class="col-md-10 col-md-offset-1">

        <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>admin/users" method="post">

            <h3><?php echo \Idno\Core\Idno::site()->language()->_('Invite new users'); ?></h3>

            <p>
            <?php echo \Idno\Core\Idno::site()->language()->_('To invite new users to the site, enter one or more email addresses below.'); ?>
            </p>

            <textarea name="invitation_emails" class="form-control" placeholder="friend@email.com"></textarea>

            <p>
                <input type="submit" class="btn btn-primary" value="<?php echo \Idno\Core\Idno::site()->language()->_('Send invite'); ?>">
                <input type="hidden" name="action" value="invite_users">
            <?php echo \Idno\Core\Idno::site()->actions()->signForm('/admin/users') ?>
            </p>

        </form>

    </div>
</div>

        <?php

}
        /*
         * Temporarily removing this feature due to some security concerns
         *
        <div class="row">
            <div class="col-md-10 col-md-offset-1">

                <form action="<?= \Idno\Core\Idno::site()->config()->getDisplayURL() ?>admin/users" method="post">

                    <h3>Create a new user</h3>

                    <p>
                        You should only do this for people whose email addresses you trust
                        from prior correspondence. An email will not be sent.
                    </p>

                    <div>
                        <input type="email" name="email" placeholder="Email address" required >
                        <input type="text" name="handle" placeholder="Username" required >
                        <input type="text" name="name" placeholder="Full name" required >
                        <input type="password" name="password1" placeholder="Password" required>
                        <input type="password" name="password2" placeholder="Password again" required>
                    </div>

                    <p>

                    </p>

                    <p>
                        <input type="submit" class="btn btn-primary" value="Add">
                        <input type="hidden" name="action" value="add_user">
                        <?= \Idno\Core\Idno::site()->actions()->signForm('/admin/users') ?>
                    </p>

                </form>
            </div>
        </div>
        */

if (!empty($vars['invitations'])) {

    ?>

            <div class="row">
                <div class="col-md-10 col-md-offset-1">
                    <h3><?php echo \Idno\Core\Idno::site()->language()->_('Invitations'); ?></h3>
                </div>
            </div>
            <div class="row">
                <div class="col-md-10 col-md-offset-1">
                    <div class="pane">
                        <?php

                        foreach ($vars['invitations'] as $invitation) {

                            ?>
                                <div class="row ">
                                    <div class="col-lg-3 ">
                                        <p class="user-tbl">
                                        <?php echo $invitation->email ?>
                                        </p>
                                    </div>
                                    <div class="col-lg-3 ">
                                        <p class="user-tbl">
                                            <small><strong><?php echo \Idno\Core\Idno::site()->language()->_('Sent'); ?></strong><br>
                                            <time datetime="<?php echo date('r', $invitation->created) ?>"
                                                  class="dt-published"><?php echo date('r', $invitation->created) ?></time></small>
                                        </p>
                                    </div>
                                    <div class="col-lg-5">
                                        <p class="user-tbl" style="text-align: right">
                                            <small>
                                            <?php

                                                echo \Idno\Core\Idno::site()->actions()->createLink(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/users', '<i class="fa fa-sync"></i> Resend', array('invitation_id' => $invitation->getID(), 'action' => 'resend_invitation'), array('class' => '', 'confirm' => true, 'confirm-text' => 'Are you sure? The user will receive a second email.')) . '<br>';

                                            ?>
                                            <?php

                                                echo \Idno\Core\Idno::site()->actions()->createLink(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/users', '<i class="fa fa-times"></i> Remove', array('invitation_id' => $invitation->getID(), 'action' => 'remove_invitation'), array('class' => '', 'confirm' => true, 'confirm-text' => 'Are you sure? The user won\'t be able to register.')) . '<br>';

                                            ?>
                                            </small>
                                        </p>
                                    </div>
                                </div>
                                <?php

                        }

                        ?>
                    </div>
                </div>
            </div>

    <?php

}

?>
        <div class="row">
            <div class="col-md-10 col-md-offset-1">

                <form action="<?php echo \Idno\Core\Idno::site()->config()->getDisplayURL() ?>admin/users" method="post">

                    <h3><?php echo \Idno\Core\Idno::site()->language()->_('Block email addresses'); ?></h3>

                    <p>
                        <?php echo \Idno\Core\Idno::site()->language()->_('By blocking email addresses, you prevent people using those email addresses from registering on your site. Enter the email addresses you want to block below.'); ?>
                    </p>

                    <textarea name="blocked_emails" class="form-control" placeholder="user@email.com"></textarea>

                    <p>
                        <input type="submit" class="btn btn-primary" value="<?php echo \Idno\Core\Idno::site()->language()->_('Block email addresses'); ?>">
                        <input type="hidden" name="action" value="block_emails">
                        <?php echo \Idno\Core\Idno::site()->actions()->signForm('/admin/users') ?>
                    </p>

                </form>

            </div>
        </div>
        <?php

        if ($blocked_emails = \Idno\Core\Idno::site()->config()->getBlockedEmails()) {

            ?>
            <div class="row">
                <div class="col-md-10 col-md-offset-1">
                    <h3><?php echo \Idno\Core\Idno::site()->language()->_('Blocked email addresses'); ?></h3>
                </div>
            </div>
            <div class="row">
                <div class="col-md-10 col-md-offset-1">
                    <div class="pane">
                        <?php

                        foreach ($blocked_emails as $email) {

                            ?>
                                <div class="row ">
                                    <div class="col-sm-4 col-xs-12">
                                        <p class="user-tbl">
                                        <?php echo $email ?>
                                        </p>
                                    </div>
                                    <div class="col-sm-8 col-xs-12">
                                        <p class="user-tbl" style="text-align: right">
                                            <small>
                                            <?php

                                                echo \Idno\Core\Idno::site()->actions()->createLink(\Idno\Core\Idno::site()->config()->getDisplayURL() . 'admin/users', '<i class="fa fa-times"></i> Remove block', array('blocked_emails' => $email, 'action' => 'unblock_emails'), array('class' => '', 'confirm' => true, 'confirm-text' => 'Are you sure? The user will be able to log in and post again.')) . '<br>';

                                            ?>
                                            </small>
                                        </p>
                                    </div>
                                </div>
                                <?php

                        }

                        ?>
                    </div>
                </div>
            </div>
            <?php

        }

        ?>
        <?php

        echo $this->draw('admin/users/extensions');
