<?php

    $lang = \Idno\Core\Idno::site()->language();
    $config = \Idno\Core\Idno::site()->config();
    ?>
<div class="idno-gate">
    <div class="idno-editor">
        <h1 class="idno-editor-heading"><?= $lang->_('Reset your password') ?></h1>
        <p class="idno-gate-lead"><?= $lang->esc_('Enter the email address of your %s account, and we will send you a link to choose a new password.', [$config->title]) ?></p>

        <?= $this->draw('account/password/nomail') ?>

        <form action="<?= $config->getDisplayURL() ?>account/password" method="post">
            <div class="idno-form-field">
                <label class="idno-label" for="reset-email"><?= $lang->_('Your email address') ?></label>
                <input type="email" id="reset-email" name="email" class="idno-input" required autofocus
                       autocomplete="email" autocapitalize="off" placeholder="you@example.com">
            </div>
            <?= $this->__(['action' => '/account/password'])->draw('forms/input/captcha') ?>
            <div class="idno-gate-actions">
                <button type="submit" class="idno-btn idno-btn-primary"><?= $lang->_('Send password reset email') ?></button>
            </div>
            <?= \Idno\Core\Idno::site()->actions()->signForm('/account/password') ?>
        </form>

        <p class="idno-gate-foot">
            <a href="<?= $config->getDisplayURL() ?>session/login"><?= $lang->_('Back to sign in') ?></a>
        </p>
    </div>
</div>
