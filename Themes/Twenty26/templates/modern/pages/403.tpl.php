<?php

    /*
     * "You can't see this". For a visitor to a private site it is the front door: it says the
     * site is private and how to get in. No script: a visitor is not sent on to the login
     * page by one.
     */

    $lang = \Idno\Core\Idno::site()->language();
    $config = \Idno\Core\Idno::site()->config();
    $login_url = \Idno\Core\Idno::site()->currentPage()->forwardToLogin($_SERVER['REQUEST_URI'] ?? '', true);
    ?>
<div class="idno-gate idno-gate-wide">
    <div class="idno-editor">
        <?php if (!\Idno\Core\Idno::site()->session()->isLoggedIn()) { ?>
            <?php if (!$config->isPublicSite()) { ?>
                <h1 class="idno-editor-heading"><?= $lang->esc_('%s is private', [$config->title]) ?></h1>
                <p class="idno-gate-lead"><?= $lang->_('Only members can read this community. If you are a member, sign in. If you have been invited, use the link in your invitation email to create your account.') ?></p>
            <?php } else { ?>
                <h1 class="idno-editor-heading"><?= $lang->_('Sign in to continue') ?></h1>
                <p class="idno-gate-lead"><?= $lang->_('You need to be signed in to see this page.') ?></p>
            <?php } ?>
            <div class="idno-gate-actions">
                <a class="idno-btn idno-btn-primary" href="<?= htmlspecialchars($login_url) ?>"><?= $lang->_('Sign in') ?></a>
            </div>
            <p class="idno-gate-foot">
                <a href="<?= $config->getDisplayURL() ?>account/password"><?= $lang->_('Forgot your password?') ?></a>
            </p>
        <?php } else { ?>
            <h1 class="idno-editor-heading p-name"><?= $lang->_("You don't have access to this page") ?></h1>
            <p class="idno-gate-lead"><?= $lang->_("Your account doesn't have the permissions this page needs.") ?></p>
            <div class="idno-gate-actions">
                <a class="idno-btn idno-btn-primary" href="<?= $config->getDisplayURL() ?>"><?= $lang->_('Go to the home page') ?></a>
            </div>
        <?php } ?>
    </div>
</div>
