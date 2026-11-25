<?php

    $lang = \Idno\Core\Idno::site()->language();
    $config = \Idno\Core\Idno::site()->config();
    ?>
<div class="idno-gate">
    <div class="idno-editor">
        <h1 class="idno-editor-heading"><?= $lang->_('Choose a new password') ?></h1>

        <form action="<?= $config->getDisplayURL() ?>account/password/reset" method="post">
            <div class="idno-form-field">
                <label class="idno-label" for="reset-email"><?= $lang->_('Your email address') ?></label>
                <input type="email" id="reset-email" name="email" class="idno-input" readonly required autocomplete="username"
                       value="<?= htmlspecialchars((string) ($vars['email'] ?? '')) ?>">
            </div>
            <div class="idno-form-field">
                <label class="idno-label" for="reset-password"><?= $lang->_('New password') ?></label>
                <input type="password" id="reset-password" name="password" class="idno-input" required minlength="7" autofocus
                       autocomplete="new-password" aria-describedby="reset-password-help">
                <p class="idno-form-help" id="reset-password-help"><?= $lang->_('At least 7 characters.') ?></p>
            </div>
            <div class="idno-form-field">
                <label class="idno-label" for="reset-password2"><?= $lang->_('Your new password again') ?></label>
                <input type="password" id="reset-password2" name="password2" class="idno-input" required minlength="7"
                       autocomplete="new-password">
            </div>
            <div class="idno-gate-actions">
                <button type="submit" class="idno-btn idno-btn-primary"><?= $lang->_('Save new password') ?></button>
            </div>
            <input type="hidden" name="code" value="<?= htmlspecialchars((string) ($vars['code'] ?? '')) ?>">
            <?= \Idno\Core\Idno::site()->actions()->signForm('/account/password/reset') ?>
        </form>
    </div>
</div>
