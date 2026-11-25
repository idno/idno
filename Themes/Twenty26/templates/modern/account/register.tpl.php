<?php

    $lang = \Idno\Core\Idno::site()->language();
    $config = \Idno\Core\Idno::site()->config();
    $invited = !empty($vars['invited']);
    ?>
<div class="idno-gate idno-gate-wide">
    <div class="idno-editor">
        <h1 class="idno-editor-heading">
            <?php if ($invited) { ?>
                <?= $lang->esc_('Join %s', [$config->title]) ?>
            <?php } else { ?>
                <?= $lang->_('Create your account') ?>
            <?php } ?>
        </h1>

        <?php if ($invited) { ?>
            <p class="idno-gate-lead">
                <?php if (!empty($vars['inviter'])) { ?>
                    <?= $lang->esc_('%s invited you. Choose a name, a handle and a password to finish setting up your account.', [$vars['inviter']]) ?>
                <?php } else { ?>
                    <?= $lang->_('You have been invited. Choose a name, a handle and a password to finish setting up your account.') ?>
                <?php } ?>
            </p>
            <?php if (!empty($vars['is_admin'])) { ?>
                <div class="idno-notice" role="note">
                    <strong><?= $lang->_('You are invited as an administrator.') ?></strong>
                    <?= $lang->_('Administrators can change the site\'s settings, manage its members and see everything posted here.') ?>
                </div>
            <?php } ?>
        <?php } ?>

        <form action="<?= $config->getDisplayURL() ?>account/register" method="post">
            <div class="idno-form-field">
                <label class="idno-label" for="register-email"><?= $lang->_('Your email address') ?></label>
                <input type="email" id="register-email" name="email" class="idno-input" required
                       value="<?= htmlspecialchars((string) ($vars['email'] ?? '')) ?>"
                       autocapitalize="off" autocomplete="email"
                       <?php if ($invited) { ?>readonly aria-describedby="register-email-help"<?php } ?>>
                <?php if ($invited) { ?>
                    <p class="idno-form-help" id="register-email-help"><?= $lang->_('The invitation is for this address, so it can\'t be changed here. You can change it in your settings afterwards.') ?></p>
                <?php } ?>
            </div>

            <div class="idno-form-field">
                <label class="idno-label" for="register-name"><?= $lang->_('Your name') ?></label>
                <input type="text" id="register-name" name="name" class="idno-input" autocomplete="name"
                       value="<?= htmlspecialchars((string) ($vars['name'] ?? '')) ?>">
            </div>

            <div class="idno-form-field">
                <label class="idno-label" for="register-handle"><?= $lang->_('Choose a handle') ?></label>
                <input type="text" id="register-handle" name="handle" class="idno-input" required maxlength="32"
                       pattern="[A-Za-z0-9_]+" autocapitalize="off" autocorrect="off" spellcheck="false" autocomplete="username"
                       aria-describedby="register-handle-help"
                       value="<?= htmlspecialchars((string) ($vars['handle'] ?? '')) ?>">
                <p class="idno-form-help" id="register-handle-help"><?= $lang->_('Your handle identifies you on the site and is part of your profile\'s address. Letters, numbers and underscores: for example, janedoe.') ?></p>
            </div>

            <div class="idno-form-field">
                <label class="idno-label" for="register-password"><?= $lang->_('Create a password') ?></label>
                <input type="password" id="register-password" name="password" class="idno-input" required minlength="7"
                       autocomplete="new-password" aria-describedby="register-password-help">
                <p class="idno-form-help" id="register-password-help"><?= $lang->_('At least 7 characters.') ?></p>
            </div>

            <div class="idno-form-field">
                <label class="idno-label" for="register-password2"><?= $lang->_('Your password again') ?></label>
                <input type="password" id="register-password2" name="password2" class="idno-input" required minlength="7"
                       autocomplete="new-password">
            </div>

            <?= $this->__(['action' => '/account/register'])->draw('forms/input/captcha') ?>

            <div class="idno-gate-actions">
                <button type="submit" class="idno-btn idno-btn-primary"><?= $lang->_('Create account') ?></button>
            </div>
            <input type="hidden" name="code" value="<?= htmlspecialchars((string) ($vars['code'] ?? '')) ?>">
            <?= \Idno\Core\Idno::site()->actions()->signForm('/account/register') ?>
        </form>

        <p class="idno-gate-foot">
            <a href="<?= $config->getDisplayURL() ?>session/login"><?= $lang->_('Already have an account? Sign in') ?></a>
        </p>
    </div>
</div>
