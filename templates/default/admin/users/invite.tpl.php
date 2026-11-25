<?php

    /*
     * The invite form of the admin Users page, for both looks: one or more addresses and the
     * role they are invited with.
     */

    $lang = \Idno\Core\Idno::site()->language();
    $config = \Idno\Core\Idno::site()->config();
    $days = \Idno\Entities\Invitation::lifetimeDays();
    ?>
<form action="<?php echo $config->getDisplayURL() ?>admin/users" method="post" class="invite-form">
    <?php if (empty($vars['mail_configured'])) { ?>
        <div class="idno-notice idno-notice-warning alert alert-warning" role="status">
            <strong><?php echo $lang->_('No mail service is set up, so invitation emails will probably not arrive.') ?></strong>
            <?php echo $lang->_('You can still invite people: each invitation gets a link that you can copy from the list below and send yourself.') ?>
            <a href="<?php echo $config->getDisplayURL() ?>admin/email"><?php echo $lang->_('Set up email') ?></a>
        </div>
    <?php } ?>

    <div class="idno-form-group form-group">
        <label class="idno-label" for="invitation_emails"><?php echo $lang->_('Email addresses') ?></label>
        <textarea name="invitation_emails" id="invitation_emails" class="idno-textarea form-control" rows="3" required
                  placeholder="friend@example.com, colleague@example.com" aria-describedby="invitation_emails_help"></textarea>
        <p class="idno-form-help help-block" id="invitation_emails_help">
            <?php echo $lang->esc_('One or more addresses, separated by commas or new lines (%d at most). Each person gets an email with a link that works for %d days and for their address only.', [\Idno\Entities\Invitation::MAX_PER_BATCH, $days]) ?>
        </p>
    </div>

    <fieldset class="idno-form-group form-group invite-role">
        <legend class="idno-label"><?php echo $lang->_('Invite them as') ?></legend>
        <div class="idno-checkbox-group">
            <input type="radio" name="invitation_role" id="invitation_role_member" value="member" checked>
            <label for="invitation_role_member"><?php echo $lang->_('Members: they can post, comment and read the site') ?></label>
        </div>
        <div class="idno-checkbox-group">
            <input type="radio" name="invitation_role" id="invitation_role_admin" value="admin">
            <label for="invitation_role_admin"><?php echo $lang->_('Administrators: they can also change settings, manage members and invite others') ?></label>
        </div>
        <div class="idno-checkbox-group invite-confirm-admin">
            <input type="checkbox" name="confirm_admin" id="confirm_admin" value="1">
            <label for="confirm_admin"><?php echo $lang->_('I understand that administrators have full control of this site (needed to invite administrators)') ?></label>
        </div>
    </fieldset>

    <div class="idno-form-actions">
        <button type="submit" class="idno-btn idno-btn-primary btn btn-primary"><?php echo $lang->_('Send invitations') ?></button>
        <input type="hidden" name="action" value="invite_users">
        <?php echo \Idno\Core\Idno::site()->actions()->signForm('/admin/users') ?>
    </div>
</form>
