<?php

    /*
     * Shown on the password reset pages of both looks when the site has no mail service: the
     * email would probably not arrive, and the visitor should know whom to ask.
     */

if (\Idno\Core\Email::isConfigured()) {
    return;
}
?>
<div class="idno-notice idno-notice-warning alert alert-warning" role="status">
    <?php echo \Idno\Core\Idno::site()->language()->_("This site isn't set up to send email yet, so a reset email may not reach you. If it doesn't arrive, ask an administrator of the site for help.") ?>
</div>
