<?php

    $lang = \Idno\Core\Idno::site()->language();
    $config = \Idno\Core\Idno::site()->config();
    ?>
<div class="idno-gate">
    <div class="idno-editor">
        <h1 class="idno-editor-heading"><?= $lang->_('Check your inbox') ?></h1>
        <p class="idno-gate-lead"><?= $lang->_('If there is an account with that email address, we have sent it a link to choose a new password. The link works for three hours.') ?></p>
        <?= $this->draw('account/password/nomail') ?>
        <div class="idno-gate-actions">
            <a class="idno-btn idno-btn-primary" href="<?= $config->getDisplayURL() ?>session/login"><?= $lang->_('Back to sign in') ?></a>
        </div>
    </div>
</div>
