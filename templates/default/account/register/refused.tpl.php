<?php

    /*
     * What a visitor who may not register is told: the site is closed to new members, or the
     * invitation in their link cannot be used. Drawn by both looks.
     */

    $lang = \Idno\Core\Idno::site()->language();
    $config = \Idno\Core\Idno::site()->config();
    $problem = $vars['problem'] ?? 'invalid';
    ?>
<div class="idno-gate idno-gate-wide register-refused">
    <div class="idno-editor">
        <?php if ($problem === 'expired') { ?>
            <h1 class="idno-editor-heading"><?php echo $lang->_('This invitation has expired') ?></h1>
            <p class="idno-gate-lead"><?php echo $lang->esc_('Invitations to %s work for a limited time, and this one is too old. Ask the person who invited you to send it again.', [$config->title]) ?></p>
        <?php } else if ($problem === 'closed') { ?>
            <h1 class="idno-editor-heading"><?php echo $lang->_('You need an invitation to join') ?></h1>
            <p class="idno-gate-lead"><?php echo $lang->esc_('%s is open to people who have been invited. If you were invited, use the link in your invitation email.', [$config->title]) ?></p>
        <?php } else { ?>
            <h1 class="idno-editor-heading"><?php echo $lang->_("This invitation can't be used") ?></h1>
            <p class="idno-gate-lead"><?php echo $lang->_('The link may be incomplete, or the invitation may have been used already or withdrawn. Try the link in your invitation email again, or ask the person who invited you for a new one.') ?></p>
        <?php } ?>
        <div class="idno-gate-actions">
            <a class="idno-btn idno-btn-primary btn btn-primary" href="<?php echo $config->getDisplayURL() ?>session/login"><?php echo $lang->_('Sign in') ?></a>
        </div>
        <p class="idno-gate-foot"><?php echo $lang->_('Already have an account? Sign in with it instead.') ?></p>
    </div>
</div>
